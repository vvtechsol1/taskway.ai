<?php
/**
 * Taskway — chat / messaging (direct 1-on-1 and group conversations).
 * All access is scoped: a user only ever sees conversations they are a member of.
 */

declare(strict_types=1);

function chat_is_member(int $convId, int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$convId, $userId]);
    return (bool)$stmt->fetchColumn();
}

/** Members of a conversation with their user info. */
function chat_members(int $convId): array
{
    $stmt = db()->prepare('SELECT u.id, u.name, u.username, u.color FROM conversation_members cm
        JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = ? ORDER BY u.name');
    $stmt->execute([$convId]);
    return $stmt->fetchAll();
}

/** A display title + avatar for a conversation, from the current user's perspective. */
function chat_display(array $conv, int $me): array
{
    if ($conv['type'] === 'group') {
        return ['title' => $conv['name'] ?: 'Group', 'color' => '#6C5CE7', 'initial' => '👥', 'subtitle' => 'Group'];
    }
    // Direct: show the OTHER member.
    $stmt = db()->prepare('SELECT u.name, u.username, u.color FROM conversation_members cm
        JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = ? AND cm.user_id <> ? LIMIT 1');
    $stmt->execute([$conv['id'], $me]);
    $o = $stmt->fetch();
    if (!$o) return ['title' => 'You', 'color' => '#6C5CE7', 'initial' => 'Y', 'subtitle' => ''];
    $name = $o['name'] ?: $o['username'];
    return ['title' => $name, 'color' => $o['color'] ?: '#6C5CE7', 'initial' => mb_strtoupper(mb_substr($name, 0, 1)), 'subtitle' => '@' . $o['username']];
}

/** Conversations the user belongs to, newest activity first, with last message + unread count. */
function chat_conversations(int $me): array
{
    $stmt = db()->prepare('SELECT c.*, cm.last_read_id,
            (SELECT body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
            (SELECT created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.id > cm.last_read_id AND m.user_id <> ?) AS unread
        FROM conversations c
        JOIN conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
        ORDER BY COALESCE(last_at, c.created_at) DESC');
    $stmt->execute([$me, $me]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r += chat_display($r, $me);
        $r['unread'] = (int)$r['unread'];
    }
    return $rows;
}

/** Find (or create) the direct conversation between two users. */
function chat_get_or_create_direct(int $a, int $b): int
{
    if ($a === $b) return 0;
    $stmt = db()->prepare("SELECT c.id FROM conversations c
        WHERE c.type = 'direct'
          AND (SELECT COUNT(*) FROM conversation_members WHERE conversation_id = c.id) = 2
          AND EXISTS(SELECT 1 FROM conversation_members WHERE conversation_id = c.id AND user_id = ?)
          AND EXISTS(SELECT 1 FROM conversation_members WHERE conversation_id = c.id AND user_id = ?)
        LIMIT 1");
    $stmt->execute([$a, $b]);
    $id = (int)$stmt->fetchColumn();
    if ($id) return $id;

    db()->prepare("INSERT INTO conversations(type, created_by, created_at, updated_at) VALUES('direct', ?, ?, ?)")
        ->execute([$a, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO conversation_members(conversation_id, user_id) VALUES(?, ?)');
    $ins->execute([$id, $a]);
    $ins->execute([$id, $b]);
    return $id;
}

/** Create a group conversation. $members includes user ids (creator added automatically). */
function chat_create_group(string $name, array $members, int $creator): int
{
    $name = trim($name) ?: 'Group';
    db()->prepare("INSERT INTO conversations(type, name, created_by, created_at, updated_at) VALUES('group', ?, ?, ?, ?)")
        ->execute([$name, $creator, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    $ids = array_unique(array_merge([$creator], array_map('intval', $members)));
    $ins = db()->prepare('INSERT INTO conversation_members(conversation_id, user_id) VALUES(?, ?)');
    foreach ($ids as $uid) {
        if ($uid > 0 && get_user($uid)) $ins->execute([$id, $uid]);
    }
    return $id;
}

/** Messages in a conversation (only if the user is a member). $afterId for incremental polling. */
function chat_messages(int $convId, int $me, int $afterId = 0): array
{
    if (!chat_is_member($convId, $me)) return [];
    $stmt = db()->prepare('SELECT m.*, u.name, u.username, u.color FROM messages m
        JOIN users u ON u.id = m.user_id
        WHERE m.conversation_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 500');
    $stmt->execute([$convId, $afterId]);
    return $stmt->fetchAll();
}

/** Save a base64 data-URI (pasted image, attached file, or recorded voice). Returns [path,type,name] or null. */
function chat_save_attachment(string $dataUri, string $name = ''): ?array
{
    if (strpos($dataUri, 'base64,') === false) return null;
    [$header, $b64] = explode('base64,', $dataUri, 2);
    if (!preg_match('#^data:([\w/.+-]+)#', $header, $m)) return null;
    $mime = strtolower($m[1]);
    $data = base64_decode($b64, true);
    if ($data === false || $data === '') return null;
    if (strlen($data) > 6 * 1024 * 1024) return null;   // 6 MB cap

    $map = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
        'application/pdf' => 'pdf',
    ];
    if (!isset($map[$mime])) return null;
    $ext = $map[$mime];
    $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : 'file');

    $dir = BASE_DIR . '/uploads/chat';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fname = date('Ymd') . '_' . bin2hex(random_bytes(7)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $fname, $data) === false) return null;

    $clean = $name !== '' ? mb_substr(preg_replace('/[^\w.\- ]+/u', '', $name) ?: '', 0, 80) : '';
    return ['path' => 'uploads/chat/' . $fname, 'type' => $type, 'name' => $clean ?: ($type . '.' . $ext)];
}

function chat_send(int $convId, int $me, string $body, ?string $attachment = null, string $attName = ''): array
{
    $body = trim($body);
    if (mb_strlen($body) > 4000) $body = mb_substr($body, 0, 4000);
    if (!chat_is_member($convId, $me)) return ['error' => 'Not allowed.'];

    $att = null;
    if ($attachment !== null && $attachment !== '') {
        $att = chat_save_attachment($attachment, $attName);
        if (!$att) return ['error' => 'Unsupported or too-large file (max 6MB; images, audio, or PDF).'];
    }
    if ($body === '' && !$att) return ['error' => 'Empty message.'];

    db()->prepare('INSERT INTO messages(conversation_id, user_id, body, attachment, attachment_type, attachment_name, created_at)
        VALUES(?, ?, ?, ?, ?, ?, ?)')
        ->execute([$convId, $me, $body, $att['path'] ?? null, $att['type'] ?? null, $att['name'] ?? null, date('Y-m-d H:i:s')]);
    $mid = (int)db()->lastInsertId();
    db()->prepare('UPDATE conversations SET updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $convId]);
    // Sender has implicitly read up to their own message.
    db()->prepare('UPDATE conversation_members SET last_read_id = ? WHERE conversation_id = ? AND user_id = ?')
        ->execute([$mid, $convId, $me]);
    return ['id' => $mid];
}

function chat_mark_read(int $convId, int $me): void
{
    if (!chat_is_member($convId, $me)) return;
    $max = (int)db()->query('SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id = ' . (int)$convId)->fetchColumn();
    db()->prepare('UPDATE conversation_members SET last_read_id = ? WHERE conversation_id = ? AND user_id = ?')
        ->execute([$max, $convId, $me]);
}

/** Delete a chat for the user (leaves it); when nobody's left, the conversation + files are removed. */
function chat_delete_conversation(int $convId, int $me): array
{
    if (!chat_is_member($convId, $me)) return ['error' => 'Not allowed.'];
    db()->prepare('DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?')->execute([$convId, $me]);

    $remaining = (int)db()->query('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ' . (int)$convId)->fetchColumn();
    if ($remaining === 0) {
        $files = db()->query('SELECT attachment FROM messages WHERE conversation_id = ' . (int)$convId . " AND attachment IS NOT NULL AND attachment <> ''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($files as $f) { $p = BASE_DIR . '/' . $f; if (is_file($p)) @unlink($p); }
        db()->prepare('DELETE FROM messages WHERE conversation_id = ?')->execute([$convId]);
        db()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$convId]);
    }
    return ['ok' => true];
}

/** Delete a single message (only the sender can). Removes its attachment file too. */
function chat_delete_message(int $msgId, int $me): array
{
    $stmt = db()->prepare('SELECT * FROM messages WHERE id = ?');
    $stmt->execute([$msgId]);
    $m = $stmt->fetch();
    if (!$m) return ['error' => 'Message not found.'];
    if ((int)$m['user_id'] !== $me) return ['error' => 'You can only delete your own messages.'];
    if (!empty($m['attachment'])) { $p = BASE_DIR . '/' . $m['attachment']; if (is_file($p)) @unlink($p); }
    db()->prepare('DELETE FROM messages WHERE id = ?')->execute([$msgId]);
    return ['ok' => true];
}

function chat_total_unread(int $me): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(x.unread),0) FROM (
        SELECT (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = cm.conversation_id AND m.id > cm.last_read_id AND m.user_id <> ?) AS unread
        FROM conversation_members cm WHERE cm.user_id = ?) x');
    $stmt->execute([$me, $me]);
    return (int)$stmt->fetchColumn();
}
