@extends('layouts.juego')

@section('title', 'Visor · ' . $equipo->nombre)

@section('content')
    @livewire(\App\Livewire\Juego\Visor::class, ['equipoId' => $equipo->id])
@endsection
