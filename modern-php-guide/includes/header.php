<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

$pageTitle = $pageTitle ?? 'Modern PHP Field Guide';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <style>
        :root{--bg:#0f172a;--panel:#111827;--text:#e5e7eb;--muted:#9ca3af;--accent:#22d3ee;--danger:#f87171;--ok:#4ade80}
        *{box-sizing:border-box}
        body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--text)}
        .wrap{max-width:1024px;margin:0 auto;padding:1rem}
        .top{display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap}
        h1,h2,h3{margin:.3rem 0 .6rem}
        a{color:var(--accent);text-decoration:none}
        a:hover{text-decoration:underline}
        .card{background:var(--panel);border:1px solid #1f2937;border-radius:10px;padding:1rem;margin:1rem 0}
        form.grid{display:flex;flex-wrap:wrap;gap:.6rem}
        input,select,textarea,button{font:inherit;padding:.55rem .65rem;border-radius:8px;border:1px solid #374151;background:#0b1220;color:var(--text)}
        input,select,textarea{min-width:180px;flex:1}
        textarea{width:100%;min-height:90px}
        button{cursor:pointer;background:#0e7490;border-color:#155e75}
        button.danger{background:#7f1d1d;border-color:#991b1b}
        .notice{padding:.65rem .75rem;border-radius:8px;margin:.75rem 0}
        .ok{background:#052e16;border:1px solid #166534;color:#bbf7d0}
        .err{background:#3f1010;border:1px solid #7f1d1d;color:#fecaca}
        table{width:100%;border-collapse:collapse;min-width:760px}
        th,td{padding:.6rem;border-bottom:1px solid #1f2937;text-align:left;vertical-align:top}
        th{color:var(--muted);font-weight:600}
        .table-wrap{overflow-x:auto}
        .row{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
        .muted{color:var(--muted)}
        .pill{padding:.15rem .5rem;border-radius:999px;font-size:.85rem;background:#1f2937}
        .p1,.p2{border:1px solid #14532d;color:var(--ok)}
        .p3{border:1px solid #155e75;color:var(--accent)}
        .p4,.p5{border:1px solid #7f1d1d;color:var(--danger)}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top card">
        <strong>Modern PHP Field Guide & Starter Kit</strong>
        <nav class="row">
            <a href="/public/index.php">Home</a>
            <a href="/public/create.php">Create</a>
            <a href="/public/api_demo.php">API Demo</a>
        </nav>
    </div>
