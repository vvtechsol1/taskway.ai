<?php
/**
 * Taskway — minimal single-user auth. Disabled by default (personal/local use).
 * Enable a password from Settings; it is stored as a password_hash().
 */

declare(strict_types=1);

function auth_enabled(): bool
{
    return setting('auth_enabled') === '1' && (string)setting('auth_password') !== '';
}

function is_logged_in(): bool
{
    return !empty($_SESSION['taskway_auth']);
}

function attempt_login(string $password): bool
{
    $hash = (string)setting('auth_password');
    if ($hash !== '' && password_verify($password, $hash)) {
        $_SESSION['taskway_auth'] = true;
        return true;
    }
    return false;
}

function logout(): void
{
    unset($_SESSION['taskway_auth']);
}

function require_auth(): void
{
    if (auth_enabled() && !is_logged_in()) {
        redirect(page_url('login'));
    }
}
