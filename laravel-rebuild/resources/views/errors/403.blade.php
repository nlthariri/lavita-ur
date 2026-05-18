@extends('layouts.app')
@section('content')
<div class="min-h-[60vh] flex items-center justify-center">
    <div class="text-center">
        <h1 class="text-6xl font-bold text-gray-300">403</h1>
        <h2 class="mt-4 text-xl font-semibold text-gray-700">Geen toegang</h2>
        <p class="mt-2 text-gray-500">U heeft geen rechten om deze pagina te bekijken.</p>
        <a href="/" class="mt-6 inline-block px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">Terug naar home</a>
    </div>
</div>
@endsection
