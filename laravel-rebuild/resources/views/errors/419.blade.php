@extends('layouts.app')
@section('content')
<div class="min-h-[60vh] flex items-center justify-center">
    <div class="text-center">
        <h1 class="text-6xl font-bold text-gray-300">419</h1>
        <h2 class="mt-4 text-xl font-semibold text-gray-700">Sessie verlopen</h2>
        <p class="mt-2 text-gray-500">Uw sessie is verlopen. Ververs de pagina en probeer opnieuw.</p>
        <a href="/inloggen" class="mt-6 inline-block px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">Opnieuw inloggen</a>
    </div>
</div>
@endsection
