<!DOCTYPE html>
<html lang="ko">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Pinky Fleet Control · 관제</title>
        @vite('resources/js/app.js')
    </head>
    <body>
        <div id="app"></div>
        <noscript>상태 확인 화면을 사용하려면 JavaScript를 활성화해 주세요.</noscript>
    </body>
</html>
