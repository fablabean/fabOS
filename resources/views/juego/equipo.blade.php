@extends('layouts.juego')

@section('title', $equipo->nombre)

@section('content')
    @livewire(\App\Livewire\Juego\Celular::class, ['equipoId' => $equipo->id, 'aviso' => session('juego')])
@endsection
