<?php

/*
 * A stand-in renderer, served by `php -S` for the proxy tests.
 *
 * It answers every request the same way: the headers it was sent, as JSON,
 * plus whatever cookies the path asks it to set. What the proxy does with a
 * real answer is only visible against something that gives one.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/sets-cookies') {
    // Exactly as a host call's response would carry them: already encrypted
    // by Laravel, in attribute spellings Symfony would not write itself.
    header('Set-Cookie: laravel_session=from-the-host-call%3D%3D; expires=Thu, 01 Jan 2099 00:00:00 GMT; Max-Age=999; path=/; httponly; samesite=lax', false);
    header('Set-Cookie: remember_me=abc123; Path=/; HttpOnly', false);
}

$body = json_encode(['headers' => getallheaders()]);

// A HEAD answers with the length its body would have had, which is what a
// real server does and what a client has to know not to wait for.
header('Content-Type: application/json');
header('Content-Length: '.strlen($body));

if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    echo $body;
}
