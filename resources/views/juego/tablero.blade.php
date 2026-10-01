@extends('layouts.juego')

@section('title', 'Tablero · ' . $partida->nombre)
@section('ancho', 'ancho')

@section('content')
    @livewire(\App\Livewire\Juego\Tablero::class, ['partidaId' => $partida->id])
@endsection
