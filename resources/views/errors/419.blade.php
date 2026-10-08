@extends('errors::minimal')

@section('title', 'Session expired')
@section('code', '419')
@section('message', 'Session expired')
@section('hint', 'You were away for a while, so you were signed out to keep the till safe. Log in again and redo the last step.')
@section('action_url', url('/login'))
@section('action_label', 'Log in again')
