@extends('laravel-fly::layouts.info')

@section('content')
    <table class="table">
        @include('laravel-fly::partials.table',['caption'=>$caption,'data'=>$info])
    </table>
@stop

