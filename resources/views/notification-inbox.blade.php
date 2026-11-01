<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>مرکز اعلان‌ها | Notification Hub</title>
    @vite(['resources/css/app.css', 'resources/js/inbox.js'])
</head>
<body class="min-h-screen bg-slate-50 p-4 font-sans sm:p-8">
    <div id="notification-inbox"></div>
</body>
</html>
