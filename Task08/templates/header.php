<?php
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escapeHtml($pageTitle) ?> — СТО</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page">
    <header class="site-header">
        <a class="brand" href="index.php">Автосервис</a>
        <nav aria-label="Основные разделы">
            <a href="masters.php">Мастера</a>
            <a href="schedule.php">График работы</a>
            <a href="booking.php">Запись на услугу</a>
        </nav>
    </header>
    <main>
        <h1><?= escapeHtml($pageTitle) ?></h1>
        <?php if (is_string($message)): ?>
            <p class="success" role="status"><?= escapeHtml($message) ?></p>
        <?php endif; ?>
