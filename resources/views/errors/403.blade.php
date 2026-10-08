@extends('errors::minimal')

@section('title', 'Not allowed')
@section('code', '403')
@section('message', 'Staff only past this point')
@section('hint', 'Your account doesn\'t have access to this page. Ask a manager if you need it.')
