<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#059669">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Notification Hub">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/push-icon-192.png">
    <title>تنظیمات اعلان‌ها | Notification Hub</title>
    @vite(['resources/css/app.css', 'resources/js/push.js'])
</head>
<body class="min-h-screen bg-slate-50 p-4 font-sans sm:p-10">
    <div id="push-activation"></div>
</body>
</html>
