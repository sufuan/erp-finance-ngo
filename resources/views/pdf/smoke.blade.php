<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="utf-8">
<style>
    @font-face {
        font-family: 'Hind Siliguri';
        src: url('{{ public_path('fonts/HindSiliguri-Regular.woff2') }}') format('woff2');
        font-weight: 400;
    }
    @font-face {
        font-family: 'Hind Siliguri';
        src: url('{{ public_path('fonts/HindSiliguri-SemiBold.woff2') }}') format('woff2');
        font-weight: 600;
    }
    body { font-family: 'Hind Siliguri', sans-serif; line-height: 1.7; }
    h1 { font-weight: 600; }
</style>
</head>
<body>
    <h1>{{ $title ?? 'TPDA e-Sheba' }}</h1>
    <p>{{ $body ?? 'ক্ষ জ্ঞ ১২৩' }}</p>
</body>
</html>
