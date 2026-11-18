<?php
declare(strict_types=1);

/**
 * Local trust configuration for the Adminpanel release checker.
 *
 * Leave empty until the authorized GitHub repository, release ZIP asset name,
 * and maintainer-published public signing key have been supplied and verified.
 * Never put the private signing key here; keep it offline with the release
 * publisher. This file is excluded from signed update packages.
 */
return [
    'repository_url' => '',
    'asset_name' => '',
    'public_key_pem' => '',
];
