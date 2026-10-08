@extends('errors::minimal')

@section('title', 'Server error')
@section('code', '500')
@section('message', 'Engine trouble')
@section('hint', 'Something went wrong on our side. Nothing was saved halfway: money and stock changes are all-or-nothing. Try again, and tell the admin if it keeps happening.')
