<?php
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json');

try {
    blocks_expire_pending();
    $data = blocks_read();

    // Only send back what the front end needs to render — hide order metadata
    // (email, order id, temp file paths) from public view.
    $public = [];
    foreach ($data as $n => $info) {
        if (($info['status'] ?? null) === 'sold') {
            $public[$n] = [
                'status' => 'sold',
                'imgSrc' => $info['imgSrc'] ?? '',
                'link' => $info['link'] ?? '#',
                'alt' => $info['alt'] ?? '',
                'caption' => $info['caption'] ?? '',
            ];
        } elseif (($info['status'] ?? null) === 'pending') {
            $public[$n] = ['status' => 'pending'];
        }
    }

    echo json_encode($public);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load block data']);
}
