<?php
/**
 * components/header.php
 *
 * Common HTML <head>, metadata, and CSS imports.
 * Must be the first include on every page.
 */

if (!isset($PAGE_TITLE)) {
    $PAGE_TITLE = 'CampusOrbit — University Club & Venue Management';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars($PAGE_TITLE) ?></title>
    <meta name="description" content="CampusOrbit — University Club & Venue Management System">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/assets/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/assets/favicon.png">

    <!-- Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- App styles -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        /* Feedback toasts */
        .co-toast {
            pointer-events:auto;
            min-width:240px; max-width:360px;
            padding:.65rem .9rem;
            border-radius:.5rem;
            color:#fff;
            box-shadow:0 8px 24px rgba(0,0,0,.18);
            display:flex; align-items:center; gap:.5rem;
            font-weight:500;
            opacity:0; transform:translateY(-6px);
            transition:opacity .25s ease, transform .25s ease;
        }
        .co-toast.show { opacity:1; transform:translateY(0); }
        .co-toast-ok  { background:#10B981; }
        .co-toast-err { background:#EF4444; }
        .co-toast i   { font-size:1.1rem; }
    </style>
</head>
<body>