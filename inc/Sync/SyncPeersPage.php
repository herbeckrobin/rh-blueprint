<?php

declare(strict_types=1);

namespace RhBlueprint\Sync;

use RhBlueprint\Settings\SettingsPage;

final class SyncPeersPage
{
    public const TAB_ID = 'sync_network';
    public const CAPABILITY = 'manage_options';
    public const NONCE_ADD = 'rhbp_peer_add';
    public const NONCE_REMOVE = 'rhbp_peer_remove';
    public const NONCE_REGEN = 'rhbp_peer_regenerate';
    public const NONCE_PULL = 'rhbp_peer_pull';
    public const NONCE_PUSH = 'rhbp_peer_push';
    public const NEW_TOKEN_TRANSIENT_PREFIX = 'rhbp_peer_new_token_';
    public const PULL_RESULT_TRANSIENT_PREFIX = 'rhbp_pull_result_';
    public const PUSH_RESULT_TRANSIENT_PREFIX = 'rhbp_push_result_';

    public function __construct(
        private readonly PeerRegistry $registry,
        private readonly PullOperation $pullOperation,
        private readonly PushOperation $pushOperation,
        private readonly SyncLog $log,
    ) {
    }

    public function boot(): void
    {
        add_action('rh-blueprint/settings/tab_content_before', [$this, 'renderInlineMessage']);
        add_action('rh-blueprint/settings/tab_content_after', [$this, 'renderPeers']);
        add_action('admin_post_rhbp_peer_add', [$this, 'handleAdd']);
        add_action('admin_post_rhbp_peer_remove', [$this, 'handleRemove']);
        add_action('admin_post_rhbp_peer_regenerate', [$this, 'handleRegenerate']);
        add_action('admin_post_rhbp_peer_pull', [$this, 'handlePull']);
        add_action('admin_post_rhbp_peer_push', [$this, 'handlePush']);
    }

    public function renderInlineMessage(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        $message = isset($_GET['rhbp_message']) ? sanitize_key((string) $_GET['rhbp_message']) : '';
        if ($message === '') {
            return;
        }

        $map = [
            'peer_added' => ['success', __('Peer wurde hinzugefuegt.', 'rh-blueprint')],
            'peer_removed' => ['success', __('Peer wurde entfernt.', 'rh-blueprint')],
            'peer_regenerated' => ['success', __('Token wurde neu generiert.', 'rh-blueprint')],
            'peer_missing_fields' => ['warning', __('Name und URL sind Pflicht.', 'rh-blueprint')],
            'peer_invalid_url' => ['error', __('Die URL ist nicht gueltig.', 'rh-blueprint')],
            'peer_name_exists' => ['error', __('Ein Peer mit diesem Namen existiert bereits.', 'rh-blueprint')],
            'peer_not_found' => ['error', __('Peer nicht gefunden.', 'rh-blueprint')],
            'pull_success' => ['success', __('Pull erfolgreich abgeschlossen.', 'rh-blueprint')],
            'pull_failed' => ['error', __('Pull fehlgeschlagen.', 'rh-blueprint')],
            'push_success' => ['success', __('Push erfolgreich abgeschlossen.', 'rh-blueprint')],
            'push_failed' => ['error', __('Push fehlgeschlagen.', 'rh-blueprint')],
        ];

        if (!isset($map[$message])) {
            return;
        }

        [$type, $text] = $map[$message];
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($text)
        );
    }

    public function renderPeers(string $tabId): void
    {
        if ($tabId !== self::TAB_ID) {
            return;
        }

        echo '<div class="rhbp-sync">';
        echo '<h2 class="rhbp-sync__heading">' . esc_html__('Sync Network', 'rh-blueprint') . '</h2>';
        echo '<p class="rhbp-sync__intro">' . esc_html__('Peers sind andere WordPress-Instanzen, mit denen diese Site Datenbank und Uploads synchronisieren kann. Jeder Peer hat einen eigenen Token als geteiltes Geheimnis fuer HMAC-Authentifizierung.', 'rh-blueprint') . '</p>';

        $this->renderNewTokenNotice();
        $this->renderPullResultNotice();
        $this->renderPushResultNotice();
        $this->renderPeerList();
        $this->renderAddForm();
        $this->renderHistory();

        echo '</div>';
    }

    public function handleAdd(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-blueprint'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ADD);

        $name = isset($_POST['peer_name']) ? sanitize_text_field((string) $_POST['peer_name']) : '';
        $url = isset($_POST['peer_url']) ? esc_url_raw((string) $_POST['peer_url']) : '';
        $tokenInput = isset($_POST['peer_token']) ? sanitize_text_field((string) $_POST['peer_token']) : '';

        if ($name === '' || $url === '') {
            $this->redirect('peer_missing_fields');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->redirect('peer_invalid_url');
        }

        if ($this->registry->getByName($name) !== null) {
            $this->redirect('peer_name_exists');
        }

        $peer = Peer::create($name, $url, $tokenInput !== '' ? $tokenInput : null);
        $this->registry->add($peer);

        set_transient(self::NEW_TOKEN_TRANSIENT_PREFIX . get_current_user_id(), [
            'peer_id' => $peer->id,
            'token' => $peer->token,
        ], 60);

        $this->redirect('peer_added');
    }

    public function handleRemove(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-blueprint'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_REMOVE);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        if ($id !== '') {
            $this->registry->remove($id);
            $this->redirect('peer_removed');
        }

        $this->redirect('peer_not_found');
    }

    public function handleRegenerate(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-blueprint'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_REGEN);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            $this->redirect('peer_not_found');
        }

        $newToken = Peer::generateToken();
        $this->registry->update($peer->withToken($newToken));

        set_transient(self::NEW_TOKEN_TRANSIENT_PREFIX . get_current_user_id(), [
            'peer_id' => $peer->id,
            'token' => $newToken,
        ], 60);

        $this->redirect('peer_regenerated');
    }

    public function handlePull(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-blueprint'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_PULL);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            $this->redirect('peer_not_found');
        }

        $result = $this->pullOperation->execute($peer);

        set_transient(self::PULL_RESULT_TRANSIENT_PREFIX . get_current_user_id(), [
            'peer_id' => $peer->id,
            'peer_name' => $peer->name,
            'success' => $result->success,
            'bytes' => $result->bytes,
            'duration_ms' => $result->durationMs,
            'error' => $result->error,
        ], 60);

        $this->redirect($result->success ? 'pull_success' : 'pull_failed');
    }

    public function handlePush(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rh-blueprint'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_PUSH);

        $id = isset($_POST['peer_id']) ? sanitize_text_field((string) $_POST['peer_id']) : '';
        $peer = $id !== '' ? $this->registry->get($id) : null;
        if ($peer === null) {
            $this->redirect('peer_not_found');
        }

        $result = $this->pushOperation->execute($peer);

        set_transient(self::PUSH_RESULT_TRANSIENT_PREFIX . get_current_user_id(), [
            'peer_id' => $peer->id,
            'peer_name' => $peer->name,
            'success' => $result->success,
            'bytes' => $result->bytes,
            'chunks' => $result->chunks,
            'duration_ms' => $result->durationMs,
            'remote_import_ms' => $result->remoteImportMs,
            'error' => $result->error,
        ], 60);

        $this->redirect($result->success ? 'push_success' : 'push_failed');
    }

    private function redirect(string $message): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => SettingsPage::MENU_SLUG,
            'tab' => self::TAB_ID,
            'rhbp_message' => $message,
        ], admin_url('options-general.php')));
        exit;
    }

    private function renderNewTokenNotice(): void
    {
        $transientKey = self::NEW_TOKEN_TRANSIENT_PREFIX . get_current_user_id();
        $data = get_transient($transientKey);

        if (!is_array($data) || !isset($data['token'], $data['peer_id'])) {
            return;
        }

        delete_transient($transientKey);

        $peer = $this->registry->get((string) $data['peer_id']);
        if ($peer === null) {
            return;
        }

        echo '<div class="rhbp-sync-token-notice">';
        echo '<div class="rhbp-sync-token-notice__header">';
        echo '<span class="dashicons dashicons-shield" aria-hidden="true"></span>';
        echo '<strong>' . esc_html__('Neues Token fuer Peer', 'rh-blueprint') . ' „' . esc_html($peer->name) . '"</strong>';
        echo '</div>';
        echo '<p>' . esc_html__('Kopiere das Token jetzt — es wird nach dem Verlassen dieser Seite nicht mehr im Klartext angezeigt.', 'rh-blueprint') . '</p>';
        echo '<code class="rhbp-sync-token-notice__token">' . esc_html((string) $data['token']) . '</code>';
        echo '</div>';
    }

    private function renderPullResultNotice(): void
    {
        $transientKey = self::PULL_RESULT_TRANSIENT_PREFIX . get_current_user_id();
        $data = get_transient($transientKey);

        if (!is_array($data) || !isset($data['peer_name'])) {
            return;
        }

        delete_transient($transientKey);

        $success = !empty($data['success']);
        $bytes = isset($data['bytes']) ? (int) $data['bytes'] : 0;
        $duration = isset($data['duration_ms']) ? (int) $data['duration_ms'] : 0;
        $error = isset($data['error']) ? (string) $data['error'] : '';

        $modifier = $success ? 'success' : 'error';

        echo '<div class="rhbp-pull-result rhbp-pull-result--' . esc_attr($modifier) . '">';
        echo '<div class="rhbp-pull-result__header">';
        echo '<span class="dashicons dashicons-' . ($success ? 'yes-alt' : 'warning') . '" aria-hidden="true"></span>';
        echo '<strong>';
        if ($success) {
            printf(
                /* translators: %s: peer name */
                esc_html__('Pull von "%s" erfolgreich', 'rh-blueprint'),
                esc_html((string) $data['peer_name'])
            );
        } else {
            printf(
                /* translators: %s: peer name */
                esc_html__('Pull von "%s" fehlgeschlagen', 'rh-blueprint'),
                esc_html((string) $data['peer_name'])
            );
        }
        echo '</strong>';
        echo '</div>';

        if ($success) {
            echo '<p>';
            printf(
                /* translators: 1: bytes, 2: duration ms */
                esc_html__('%1$s in %2$d ms gezogen und importiert.', 'rh-blueprint'),
                esc_html(size_format($bytes, 2) ?: $bytes . ' B'),
                $duration
            );
            echo '</p>';
        } else {
            echo '<p>' . esc_html($error) . '</p>';
        }

        echo '</div>';
    }

    private function renderPushResultNotice(): void
    {
        $transientKey = self::PUSH_RESULT_TRANSIENT_PREFIX . get_current_user_id();
        $data = get_transient($transientKey);

        if (!is_array($data) || !isset($data['peer_name'])) {
            return;
        }

        delete_transient($transientKey);

        $success = !empty($data['success']);
        $bytes = isset($data['bytes']) ? (int) $data['bytes'] : 0;
        $chunks = isset($data['chunks']) ? (int) $data['chunks'] : 0;
        $duration = isset($data['duration_ms']) ? (int) $data['duration_ms'] : 0;
        $remoteMs = isset($data['remote_import_ms']) && $data['remote_import_ms'] !== null ? (int) $data['remote_import_ms'] : null;
        $error = isset($data['error']) ? (string) $data['error'] : '';

        $modifier = $success ? 'success' : 'error';

        echo '<div class="rhbp-pull-result rhbp-pull-result--' . esc_attr($modifier) . '">';
        echo '<div class="rhbp-pull-result__header">';
        echo '<span class="dashicons dashicons-' . ($success ? 'yes-alt' : 'warning') . '" aria-hidden="true"></span>';
        echo '<strong>';
        if ($success) {
            printf(
                /* translators: %s: peer name */
                esc_html__('Push zu "%s" erfolgreich', 'rh-blueprint'),
                esc_html((string) $data['peer_name'])
            );
        } else {
            printf(
                /* translators: %s: peer name */
                esc_html__('Push zu "%s" fehlgeschlagen', 'rh-blueprint'),
                esc_html((string) $data['peer_name'])
            );
        }
        echo '</strong>';
        echo '</div>';

        if ($success) {
            echo '<p>';
            if ($remoteMs !== null) {
                printf(
                    /* translators: 1: bytes, 2: chunks count, 3: total duration, 4: remote import duration */
                    esc_html__('%1$s in %2$d Chunks hochgeladen — %3$d ms gesamt (davon %4$d ms Remote-Import).', 'rh-blueprint'),
                    esc_html(size_format($bytes, 2) ?: $bytes . ' B'),
                    $chunks,
                    $duration,
                    $remoteMs
                );
            } else {
                printf(
                    /* translators: 1: bytes, 2: chunks, 3: duration */
                    esc_html__('%1$s in %2$d Chunks in %3$d ms hochgeladen.', 'rh-blueprint'),
                    esc_html(size_format($bytes, 2) ?: $bytes . ' B'),
                    $chunks,
                    $duration
                );
            }
            echo '</p>';
        } else {
            echo '<p>' . esc_html($error) . '</p>';
        }

        echo '</div>';
    }

    private function renderPeerList(): void
    {
        $peers = $this->registry->all();

        if ($peers === []) {
            echo '<div class="rhbp-empty rhbp-sync__empty">';
            echo esc_html__('Noch keine Peers. Lege unten den ersten an um loszulegen.', 'rh-blueprint');
            echo '</div>';
            return;
        }

        echo '<div class="rhbp-peer-grid">';
        foreach ($peers as $peer) {
            $this->renderPeerCard($peer);
        }
        echo '</div>';
    }

    private function renderPeerCard(Peer $peer): void
    {
        echo '<div class="rhbp-peer-card">';
        echo '<div class="rhbp-peer-card__header">';
        echo '<div class="rhbp-peer-card__title">';
        echo '<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>';
        echo '<strong>' . esc_html($peer->name) . '</strong>';
        echo '</div>';
        echo '<span class="rhbp-peer-card__status rhbp-peer-card__status--unknown">' . esc_html__('Status unbekannt', 'rh-blueprint') . '</span>';
        echo '</div>';

        echo '<a class="rhbp-peer-card__url" href="' . esc_url($peer->url) . '" target="_blank" rel="noopener">' . esc_html($peer->url) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>';

        echo '<dl class="rhbp-peer-card__meta">';
        echo '<dt>' . esc_html__('Token', 'rh-blueprint') . '</dt>';
        echo '<dd><code>' . esc_html($peer->maskedToken()) . '</code></dd>';
        echo '<dt>' . esc_html__('Erstellt', 'rh-blueprint') . '</dt>';
        echo '<dd>' . esc_html(wp_date('Y-m-d H:i', $peer->createdAt)) . '</dd>';
        echo '<dt>' . esc_html__('Letzter Sync', 'rh-blueprint') . '</dt>';
        echo '<dd>' . esc_html($peer->lastSync === null ? '—' : wp_date('Y-m-d H:i', (int) $peer->lastSync['timestamp'])) . '</dd>';
        echo '</dl>';

        echo '<div class="rhbp-peer-card__actions">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(self::NONCE_PULL);
        echo '<input type="hidden" name="action" value="rhbp_peer_pull" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="button button-primary rhbp-peer-card__pull" onclick="return confirm(\'Jetzt von diesem Peer pullen? Die lokale Datenbank wird ueberschrieben (mit Safety-Backup).\')">';
        echo '<span class="dashicons dashicons-download" aria-hidden="true"></span> ' . esc_html__('Pull', 'rh-blueprint');
        echo '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(self::NONCE_PUSH);
        echo '<input type="hidden" name="action" value="rhbp_peer_push" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="button rhbp-peer-card__push" onclick="return confirm(\'Jetzt zu diesem Peer pushen? Die Datenbank dort wird ueberschrieben (mit Safety-Backup).\')">';
        echo '<span class="dashicons dashicons-upload" aria-hidden="true"></span> ' . esc_html__('Push', 'rh-blueprint');
        echo '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(self::NONCE_REGEN);
        echo '<input type="hidden" name="action" value="rhbp_peer_regenerate" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="button" onclick="return confirm(\'Token neu generieren? Das alte Token wird ungueltig.\')">';
        echo '<span class="dashicons dashicons-update" aria-hidden="true"></span> ' . esc_html__('Token neu', 'rh-blueprint');
        echo '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(self::NONCE_REMOVE);
        echo '<input type="hidden" name="action" value="rhbp_peer_remove" />';
        echo '<input type="hidden" name="peer_id" value="' . esc_attr($peer->id) . '" />';
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Peer wirklich entfernen?\')">';
        echo '<span class="dashicons dashicons-trash" aria-hidden="true"></span> ' . esc_html__('Entfernen', 'rh-blueprint');
        echo '</button>';
        echo '</form>';

        echo '</div>';
        echo '</div>';
    }

    private function renderHistory(): void
    {
        $entries = $this->log->all();

        if ($entries === []) {
            return;
        }

        $limit = 20;
        $entries = array_slice($entries, 0, $limit);

        echo '<div class="rhbp-sync-history">';
        echo '<h3><span class="dashicons dashicons-backup" aria-hidden="true"></span> ' . esc_html__('Verlauf', 'rh-blueprint') . '</h3>';
        echo '<table class="rhbp-db-table rhbp-history-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Zeit', 'rh-blueprint') . '</th>';
        echo '<th>' . esc_html__('Peer', 'rh-blueprint') . '</th>';
        echo '<th>' . esc_html__('Richtung', 'rh-blueprint') . '</th>';
        echo '<th>' . esc_html__('Status', 'rh-blueprint') . '</th>';
        echo '<th>' . esc_html__('Groesse', 'rh-blueprint') . '</th>';
        echo '<th>' . esc_html__('Dauer', 'rh-blueprint') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $peerName = isset($entry['peer_name']) ? (string) $entry['peer_name'] : '—';
            $direction = isset($entry['direction']) ? (string) $entry['direction'] : '';
            $status = isset($entry['status']) ? (string) $entry['status'] : '';
            $bytes = isset($entry['bytes']) ? (int) $entry['bytes'] : 0;
            $durationMs = isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : 0;
            $error = isset($entry['error']) ? (string) $entry['error'] : '';

            $statusClass = $status === 'success' ? 'ok' : 'fail';

            echo '<tr>';
            echo '<td>' . esc_html(wp_date('Y-m-d H:i', $timestamp)) . '</td>';
            echo '<td><strong>' . esc_html($peerName) . '</strong></td>';
            echo '<td><span class="rhbp-history-direction">' . esc_html($direction) . '</span></td>';
            echo '<td><span class="rhbp-history-status rhbp-history-status--' . esc_attr($statusClass) . '">' . esc_html($status) . '</span></td>';
            echo '<td>' . esc_html($bytes > 0 ? (size_format($bytes, 2) ?: $bytes . ' B') : '—') . '</td>';
            echo '<td>' . esc_html($durationMs > 0 ? $durationMs . ' ms' : '—') . '</td>';
            echo '</tr>';

            if ($error !== '') {
                echo '<tr class="rhbp-history-error-row">';
                echo '<td colspan="6"><code>' . esc_html($error) . '</code></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function renderAddForm(): void
    {
        echo '<div class="rhbp-sync-add">';
        echo '<h3>' . esc_html__('Neuen Peer hinzufuegen', 'rh-blueprint') . '</h3>';
        echo '<p class="description">' . esc_html__('Ein Peer ist eine andere WordPress-Site, die das rh-blueprint Plugin aktiv hat. Der Token muss auf beiden Seiten identisch gespeichert sein.', 'rh-blueprint') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rhbp-sync-add__form">';
        wp_nonce_field(self::NONCE_ADD);
        echo '<input type="hidden" name="action" value="rhbp_peer_add" />';

        echo '<div class="rhbp-sync-add__field">';
        echo '<label for="rhbp-peer-name">' . esc_html__('Name', 'rh-blueprint') . '</label>';
        echo '<input type="text" id="rhbp-peer-name" name="peer_name" placeholder="stage" required />';
        echo '<p class="description">' . esc_html__('Kurzer Bezeichner, z.B. "stage" oder "prod".', 'rh-blueprint') . '</p>';
        echo '</div>';

        echo '<div class="rhbp-sync-add__field">';
        echo '<label for="rhbp-peer-url">' . esc_html__('URL', 'rh-blueprint') . '</label>';
        echo '<input type="url" id="rhbp-peer-url" name="peer_url" placeholder="https://stage.example.com" required />';
        echo '<p class="description">' . esc_html__('Basis-URL der Ziel-Instanz (ohne trailing slash).', 'rh-blueprint') . '</p>';
        echo '</div>';

        echo '<div class="rhbp-sync-add__field">';
        echo '<label for="rhbp-peer-token">' . esc_html__('Token (optional)', 'rh-blueprint') . '</label>';
        echo '<input type="text" id="rhbp-peer-token" name="peer_token" placeholder="' . esc_attr__('Leer lassen fuer automatische Generierung', 'rh-blueprint') . '" />';
        echo '<p class="description">' . esc_html__('Wenn der Peer schon einen Token hat (z.B. von der Gegenseite), hier einfuegen. Sonst wird ein neuer generiert.', 'rh-blueprint') . '</p>';
        echo '</div>';

        echo '<div class="rhbp-sync-add__actions">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Peer hinzufuegen', 'rh-blueprint') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }
}
