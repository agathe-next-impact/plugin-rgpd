# Plan de Développement — OmniPrivacy Pro

## 1. Architecture Générale

### Structure du plugin

```
omniprivacy-pro/
├── omniprivacy-pro.php                 # Point d'entrée (header WordPress)
├── uninstall.php                       # Nettoyage à la désinstallation
├── composer.json                       # Dépendances PHP (DOMPDF, etc.)
├── assets/
│   ├── css/
│   │   ├── admin.css                   # Styles back-office
│   │   └── front.css                   # Styles portail visiteur
│   └── js/
│       ├── admin-scan.js               # UI scan PII (AJAX, progression)
│       ├── admin-dashboard.js          # Tableaux de bord / graphiques
│       └── front-portal.js             # Portail visiteur (sélection données)
├── includes/
│   ├── class-omniprivacy-loader.php    # Orchestrateur hooks/filters
│   ├── class-omniprivacy-activator.php # Création tables à l'activation
│   ├── class-omniprivacy-deactivator.php
│   ├── class-omniprivacy-encryption.php # Wrapper AES-256 (openssl)
│   └── class-omniprivacy-settings.php  # Page réglages centrale
├── modules/
│   ├── data-clean/
│   │   ├── class-comment-anonymizer.php
│   │   ├── class-exif-stripper.php
│   │   ├── class-log-rotator.php
│   │   └── class-data-clean-settings.php
│   ├── pii-search/
│   │   ├── class-pii-scanner.php       # Moteur de scan (regex)
│   │   ├── class-pii-results.php       # Stockage/affichage résultats
│   │   └── class-pii-actions.php       # Éditer/Anonymiser/Ignorer
│   ├── user-portal/
│   │   ├── class-magic-link.php        # Génération/validation token
│   │   ├── class-data-viewer.php       # Consultation données visiteur
│   │   ├── class-deletion-request.php  # Demande de suppression
│   │   └── class-portal-shortcode.php  # Shortcode [omniprivacy_portal]
│   └── reporting/
│       ├── class-pdf-generator.php     # Rapport PDF (DOMPDF)
│       ├── class-consent-log.php       # Registre des consentements
│       └── class-erasure-certificate.php # Certificat d'effacement
├── templates/
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── scan-results.php
│   │   ├── settings.php
│   │   ├── deletion-requests.php
│   │   └── report-preview.php
│   └── front/
│       ├── portal-login.php            # Formulaire email magic link
│       ├── portal-data.php             # Vue données utilisateur
│       └── portal-confirm.php          # Confirmation demande
├── templates/emails/
│   ├── magic-link.php
│   ├── deletion-request-admin.php
│   ├── erasure-certificate.php
│   └── deletion-approved.php
└── languages/
    ├── omniprivacy-pro.pot
    └── omniprivacy-pro-fr_FR.po
```

### Base de données — Tables personnalisées

| Table | Rôle |
|---|---|
| `{prefix}_omniprivacy_scan_results` | Résultats PII : post_id, type, contenu trouvé, statut (actif/ignoré/anonymisé) |
| `{prefix}_omniprivacy_deletion_requests` | Demandes suppression : user_email, éléments sélectionnés, statut, date |
| `{prefix}_omniprivacy_magic_tokens` | Tokens magic link : token_hash, email, expires_at, used |
| `{prefix}_omniprivacy_consent_log` | Journal consentements : cookie_categories, action, ip_hash, timestamp, payload_chiffré |
| `{prefix}_omniprivacy_audit_log` | Journal d'audit : action, actor, target, timestamp, details_chiffré |
| `{prefix}_omniprivacy_ignored_items` | Éléments marqués "sûrs" : item_type, item_id, field, marked_by, date |

---

## 2. Plan de Développement par Phases

### Phase 1 — Fondations

**Objectifs :** Mettre en place le squelette du plugin, le système d'activation/désactivation, les tables, les réglages, et le chiffrement.

**Fichiers :**
- `omniprivacy-pro.php` — Header, constantes, autoloading
- `includes/class-omniprivacy-loader.php` — Registre des hooks
- `includes/class-omniprivacy-activator.php` — Création des 6 tables avec `dbDelta()`
- `includes/class-omniprivacy-deactivator.php` — Désactivation des tâches planifiées
- `uninstall.php` — Suppression des tables et options
- `includes/class-omniprivacy-encryption.php` — Chiffrement AES-256-CBC via `openssl_encrypt/decrypt`, clé dérivée de `AUTH_KEY` + sel dédié
- `includes/class-omniprivacy-settings.php` — Page Settings API avec onglets par module

**Points techniques :**
- Requires PHP 7.4+, WP 5.8+
- Vérification `openssl` activé à l'activation
- Constante `OMNIPRIVACY_VERSION` pour migrations futures
- Namespace `OmniPrivacy\` ou préfixe `omniprivacy_` sur toutes les fonctions/classes
- i18n : `load_plugin_textdomain()` dès le chargement

---

### Phase 2 — Module Data-Clean

**Objectifs :** Automatiser l'anonymisation des commentaires, le nettoyage EXIF et la rotation des logs.

#### 2.1 Anonymisation des commentaires
- **Fichier :** `modules/data-clean/class-comment-anonymizer.php`
- **Logique :** Action Scheduler récurrente (quotidienne). Requête `$wpdb` sur `wp_comments` où `comment_date < NOW() - retention_months`. Remplacement `comment_author` → "Utilisateur Anonyme", `comment_author_email` → `anon-{id}@anonymized.local`, `comment_author_IP` → `0.0.0.0`.
- **Exception rôles :** Vérifie `user_id` → `get_userdata()` → rôle exclu → skip
- **Log :** Enregistrement dans `audit_log` du nombre de commentaires anonymisés

#### 2.2 Nettoyeur EXIF
- **Fichier :** `modules/data-clean/class-exif-stripper.php`
- **Hook :** `wp_handle_upload` (filtre, avant insertion en BDD)
- **Logique :** Pour JPEG/TIFF : lecture avec `imagecreatefromjpeg()`, réécriture avec `imagejpeg()` (écrase les EXIF). Pour PNG : `imagecreatefrompng()` + `imagepng()` (supprime les chunks texte).
- **Alternative :** Si `exif_read_data()` disponible, vérification préalable ; sinon réécriture systématique
- **Pas de dépendance externe** — fonctions GD natives de PHP

#### 2.3 Rotation des logs
- **Fichier :** `modules/data-clean/class-log-rotator.php`
- **Logique :** Tâche planifiée (hebdomadaire). Localise `WP_DEBUG_LOG` (par défaut `wp-content/debug.log`). Tronque les entrées au-delà de la période de rétention. Anonymise les IP trouvées par regex dans le fichier restant.
- **Sécurité :** Vérification que le fichier est dans `WP_CONTENT_DIR` (pas d'écriture arbitraire)

#### 2.4 Réglages Data-Clean
- **Fichier :** `modules/data-clean/class-data-clean-settings.php`
- **Options :** Période de rétention (mois), rôles exclus (checkboxes), activation/désactivation par sous-module, fréquence de rotation logs

---

### Phase 3 — Module PII Deep Search

**Objectifs :** Permettre un scan exhaustif des données personnelles dans la base, avec interface d'action.

#### 3.1 Moteur de scan
- **Fichier :** `modules/pii-search/class-pii-scanner.php`
- **Architecture :** Tâche Action Scheduler. Le scan est découpé en **batches** (ex: 100 posts par lot) pour éviter les timeouts.
- **Périmètre :**
  - `wp_posts` : `post_title`, `post_content`, `post_excerpt` (tous types publics + CPT)
  - `wp_postmeta` : `meta_value` (tous les champs)
  - `wp_comments` : `comment_author`, `comment_author_email`, `comment_content`
  - Médias : `post_title`, `_wp_attachment_metadata` (alt text stocké dans postmeta `_wp_attachment_image_alt`)
- **Patterns regex :**
  - Email : `/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/`
  - Téléphone FR : `/(?:\+33|0)\s*[1-9](?:[\s.-]*\d{2}){4}/`
  - IBAN : `/[A-Z]{2}\d{2}[\s]?[\dA-Z]{4}[\s]?(?:[\dA-Z]{4}[\s]?){2,7}[\dA-Z]{1,4}/`
  - Patterns personnalisables dans les réglages
- **Stockage :** Chaque occurrence → `scan_results` (table dédiée) avec `source_type`, `source_id`, `field_name`, `matched_value`, `pattern_type`, `status`

#### 3.2 Interface résultats
- **Fichier :** `modules/pii-search/class-pii-results.php`
- **Vue :** `WP_List_Table` avec colonnes : Type, Source (lien), Champ, Valeur trouvée, Actions
- **Filtres :** Par type de PII, par source, par statut
- **Pagination :** Standard WP_List_Table
- **Bulk actions :** Anonymiser sélection, Ignorer sélection

#### 3.3 Actions unitaires
- **Fichier :** `modules/pii-search/class-pii-actions.php`
- **Éditer :** Lien `get_edit_post_link()` / lien edit commentaire — nouvel onglet
- **Anonymiser :** AJAX → remplace la valeur dans la source par `[Censuré]` → met à jour `scan_results.status` = "anonymized" → log dans `audit_log`
- **Ignorer :** AJAX → `ignored_items` table + `scan_results.status` = "ignored"
- **Nonces :** Chaque action AJAX protégée par `wp_nonce`

---

### Phase 4 — Module User Transparency

**Objectifs :** Portail visiteur avec magic link, consultation et demandes de suppression.

#### 4.1 Magic Link
- **Fichier :** `modules/user-portal/class-magic-link.php`
- **Flux :**
  1. Visiteur entre son email dans le formulaire (shortcode `[omniprivacy_portal]`)
  2. Génération : `$token = bin2hex(random_bytes(32))` → stockage de `hash('sha256', $token)` dans `magic_tokens` avec `expires_at = NOW() + 1h`
  3. Envoi email via `wp_mail()` avec lien : `site_url('/omniprivacy-portal/?token=xxx')`
  4. Validation : comparaison du hash, vérification expiration, marquage `used = 1` après première utilisation
- **Rate limiting :** Max 3 demandes par email par heure (anti-spam)
- **Sécurité :** Le token en clair n'est jamais stocké. Seul le hash SHA-256 est en base.

#### 4.2 Consultation des données
- **Fichier :** `modules/user-portal/class-data-viewer.php`
- **Données affichées :**
  - Commentaires (`wp_comments` WHERE `comment_author_email`)
  - Compte WP (`wp_users` + `wp_usermeta` si existe)
  - Commandes WooCommerce (si actif) : numéro, date, statut (pas de montant)
  - Résultats du scan PII associés à cet email
- **Template :** Rendu front-end sobre, accessible, responsive

#### 4.3 Demande de suppression
- **Fichier :** `modules/user-portal/class-deletion-request.php`
- **Logique :** Cases à cocher par élément. Soumission → insertion dans `deletion_requests` (statut "pending"). Envoi notification admin (`wp_mail` + notification WP dashboard).
- **Bouclier légal :** Les données WooCommerce < 10 ans sont affichées avec un cadenas et une explication ("Conservation obligatoire — obligation fiscale"). Cases non cochables.

#### 4.4 Workflow admin
- **Vue :** Page admin listant les demandes (WP_List_Table)
- **Actions :** Approuver (supprime les données cochées, génère certificat), Rejeter (avec motif obligatoire), Détail
- **Post-approbation :** Suppression effective → certificat d'effacement → email au demandeur

---

### Phase 5 — Module Reporting & Accountability

#### 5.1 Générateur PDF
- **Fichier :** `modules/reporting/class-pdf-generator.php`
- **Librairie :** DOMPDF (via Composer) — pas d'API externe
- **Contenu du rapport :**
  - Score de conformité (calcul basé sur : EXIF actif, anonymisation active, logs rotés, demandes traitées < 30j)
  - Historique des nettoyages (depuis `audit_log`)
  - Résumé des demandes utilisateurs traitées
  - Date de génération, version du plugin
- **Déclenchement :** Bouton admin "Générer rapport d'audit"

#### 5.2 Registre des consentements
- **Fichier :** `modules/reporting/class-consent-log.php`
- **Logique :** Hook sur l'événement cookie consent (compatible avec les principaux banners : filtre générique `omniprivacy_consent_recorded`). Enregistrement dans `consent_log` : catégories acceptées/refusées, hash IP, timestamp, user-agent. Payload chiffré AES-256.
- **Immutabilité :** Table en INSERT-only (pas d'UPDATE/DELETE exposé). Vérification d'intégrité par chaînage de hash (chaque entrée contient le hash de la précédente).
- **Consultation :** Interface admin en lecture seule avec filtres par date

#### 5.3 Certificat d'effacement
- **Fichier :** `modules/reporting/class-erasure-certificate.php`
- **Déclenchement :** Automatique après approbation d'une demande de suppression
- **Contenu email :**
  - ID de transaction unique (`uniqid` + hash)
  - Liste des données supprimées (catégories, pas les valeurs)
  - Date/heure de l'effacement
  - Signature HMAC du certificat pour vérification ultérieure

---

## 3. Spécifications Transversales

### Sécurité
- **Nonces** sur tous les formulaires et appels AJAX
- **Capabilities** : Nouvelle capability `manage_omniprivacy` attribuée à l'admin. Toutes les pages admin vérifient `current_user_can('manage_omniprivacy')`
- **Prepared statements** : Toutes les requêtes `$wpdb` utilisent `$wpdb->prepare()`
- **Sanitization** : `sanitize_text_field()`, `sanitize_email()`, `absint()` sur toutes les entrées
- **Escape** : `esc_html()`, `esc_attr()`, `esc_url()` sur toutes les sorties
- **CSP** : Les scripts/styles admin utilisent des nonces WordPress (`wp_script_add_data`)

### Performance
- **Action Scheduler** comme dépendance (bundlé avec WooCommerce, sinon inclus via Composer)
- **Batch processing** pour tous les scans et nettoyages (configurable, défaut 100 items/lot)
- **Index DB** sur les colonnes fréquemment requêtées (`email`, `status`, `expires_at`)
- **Transients** pour le cache des résultats de scan (invalidés au nouveau scan)

### Compatibilité
- **WooCommerce** : Hooks `woocommerce_order_data_store` pour protéger les commandes < 10 ans. Scan des tables `wc_orders` et `wc_order_addresses` (HPOS).
- **Contact Form 7 / WPForms** : Scan de la table `wp_posts` (type `flamingo_inbound` pour CF7) et des tables custom WPForms.
- **Multisite** : Support `switch_to_blog()` pour les scans réseau (phase ultérieure)

### Internationalisation
- Toutes les chaînes passées par `__()` / `_e()` / `esc_html__()`
- Textdomain : `omniprivacy-pro`
- Fichier `.pot` généré, traduction FR fournie

---

## 4. Ordre de Développement Recommandé

| Étape | Contenu | Dépendances |
|---|---|---|
| **1** | Squelette plugin + Activator + Settings + Encryption | — |
| **2** | Module Data-Clean (anonymisation commentaires + EXIF) | Étape 1 |
| **3** | Module Data-Clean (rotation logs + réglages complets) | Étape 2 |
| **4** | Module PII Search (moteur scan + batch processing) | Étape 1 |
| **5** | Module PII Search (interface résultats + actions) | Étape 4 |
| **6** | Module User Portal (magic link + consultation) | Étape 1 |
| **7** | Module User Portal (demandes suppression + workflow admin) | Étape 6, Étape 5 |
| **8** | Module Reporting (registre consentements + audit log) | Étape 1 |
| **9** | Module Reporting (PDF + certificat d'effacement) | Étape 8, Étape 7 |
| **10** | Compatibilité WooCommerce + bouclier légal | Étapes 2-9 |
| **11** | i18n complète + tests + revue sécurité | Toutes |

---

## 5. Risques et Points d'Attention

| Risque | Mitigation |
|---|---|
| Scan PII sur grosse base (100k+ posts) | Batch processing + Action Scheduler + indicateur progression |
| Faux positifs regex (ex: email dans du code) | Interface "Ignorer" + patterns affinables |
| Conflits avec d'autres plugins RGPD | Pas de banner cookie intégré, focus sur la gouvernance des données |
| DOMPDF lourd en mémoire | Génération PDF limitée à 1 à la fois, `memory_limit` vérifié |
| Suppression accidentelle de données | Workflow validation admin obligatoire, bouclier légal automatique |
| Tokens magic link bruteforce | 256 bits d'entropie + rate limiting + expiration 1h + usage unique |
