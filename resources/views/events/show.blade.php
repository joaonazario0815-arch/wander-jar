@extends('layouts.app')

@section('title', $event->title . ' — Wander Jar')
@section('page-id', 'events.show')

@push('styles')
  <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    crossorigin=""
  >

  @vite('resources/css/events-show.css')
@endpush

@section('content')

@php
  $eventDate = $event->event_date ? \Carbon\Carbon::parse($event->event_date)->format('d/m/Y') : 'Sem data';
  $eventTime = $event->event_time ? \Carbon\Carbon::parse($event->event_time)->format('H:i') : null;
  $creatorName = $event->creator?->name ?? 'Utilizador';
  $hasLocation = !empty($event->location_text);
  $hasCoords = is_numeric($event->lat) && is_numeric($event->lng);
  $isFull = $participantsCount >= (int) $event->max_participants;
@endphp

<main id="eventsShow">
  <section class="wj-narrow">

    <div class="event-topbar">
      <a href="{{ route('events.index') }}" class="event-back">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        <span>Voltar aos eventos</span>
      </a>
    </div>

    <article class="event-hero">
      <div class="event-kicker">
        <i class="bi bi-calendar-event" aria-hidden="true"></i>
        <span>Evento</span>
      </div>

      <h1 class="event-title">
        {{ $event->title }}
      </h1>

      <div class="event-subline">
        <span>
          <i class="bi bi-calendar3" aria-hidden="true"></i>
          {{ $eventDate }}
        </span>

        @if($eventTime)
          <span>
            <i class="bi bi-clock" aria-hidden="true"></i>
            {{ $eventTime }}
          </span>
        @endif

        @if($hasLocation)
          <span>
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            {{ $event->location_text }}
          </span>
        @endif
      </div>

      <div class="event-pills">
        <span class="event-pill">
          <i class="bi bi-people" aria-hidden="true"></i>
          <span>
            <span data-participants-count>{{ $participantsCount }} / {{ $event->max_participants }}</span>
            participantes
          </span>
        </span>

        @if($isActive)
          <span class="event-pill event-pill--ok">
            <i class="bi bi-check-circle" aria-hidden="true"></i>
            <span>Ativo</span>
          </span>
        @else
          <span class="event-pill event-pill--off">
            <i class="bi bi-x-circle" aria-hidden="true"></i>
            <span>Inativo</span>
          </span>
        @endif

        @if($isCreator)
          <span class="event-pill event-pill--warn">
            <i class="bi bi-star" aria-hidden="true"></i>
            <span>Criado por ti</span>
          </span>
        @else
          <span
            class="event-pill event-pill--ok"
            data-joined-pill
            @if(!$isJoined) hidden @endif
          >
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <span>Estás a participar</span>
          </span>
        @endif
      </div>

      <div
        class="event-actions"
        data-event-actions
        data-join-url="{{ route('events.join', $event) }}"
        data-leave-url="{{ route('events.leave', $event) }}"
        data-max-participants="{{ $event->max_participants }}"
      >
        @if($isCreator)
          <a href="{{ route('events.edit', $event) }}" class="wj-btn wj-btn-primary">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <span>Editar evento</span>
          </a>

          <button type="button" class="wj-btn wj-btn-ghost" disabled>
            <i class="bi bi-person-check" aria-hidden="true"></i>
            <span>És o criador</span>
          </button>
        @elseif(!$isActive)
          <button type="button" class="wj-btn wj-btn-ghost" disabled>
            <i class="bi bi-x-circle" aria-hidden="true"></i>
            <span>Evento indisponível</span>
          </button>
        @elseif($isJoined)
          <form
            method="POST"
            action="{{ route('events.leave', $event) }}"
            data-event-participation-form
            data-action-type="leave"
          >
            @csrf

            <button type="submit" class="wj-btn wj-btn-danger">
              <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
              <span>Sair do evento</span>
            </button>
          </form>
        @elseif($isFull)
          <button type="button" class="wj-btn wj-btn-ghost" disabled>
            <i class="bi bi-people-fill" aria-hidden="true"></i>
            <span>Evento cheio</span>
          </button>
        @else
          <form
            method="POST"
            action="{{ route('events.join', $event) }}"
            data-event-participation-form
            data-action-type="join"
          >
            @csrf

            <button type="submit" class="wj-btn wj-btn-primary">
              <i class="bi bi-check2-circle" aria-hidden="true"></i>
              <span>Participar no evento</span>
            </button>
          </form>
        @endif
      </div>
    </article>

    <section class="event-grid">
      <article class="event-card">
        <div class="event-card__head">
          <h2 class="event-card__title">
            <i class="bi bi-card-text" aria-hidden="true"></i>
            <span>Descrição</span>
          </h2>
        </div>

        @if($event->description)
          <div class="event-text">
            {!! nl2br(e($event->description)) !!}
          </div>
        @else
          <p class="event-muted">
            Este evento ainda não tem descrição.
          </p>
        @endif
      </article>

      <aside class="event-card">
        <div class="event-card__head">
          <h2 class="event-card__title">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>Detalhes</span>
          </h2>
        </div>

        <div class="event-detail-list">
          <div class="event-detail">
            <span class="event-muted">Criado por</span>
            <strong>{{ $creatorName }}</strong>
          </div>

          <div class="event-detail">
            <span class="event-muted">Data</span>
            <strong>{{ $eventDate }}</strong>
          </div>

          @if($eventTime)
            <div class="event-detail">
              <span class="event-muted">Hora</span>
              <strong>{{ $eventTime }}</strong>
            </div>
          @endif

          <div class="event-detail">
            <span class="event-muted">Participantes</span>
            <strong data-participants-count>{{ $participantsCount }} / {{ $event->max_participants }}</strong>
          </div>

          <div class="event-detail">
            <span class="event-muted">Estado</span>
            <strong>{{ $isActive ? 'Ativo' : 'Inativo' }}</strong>
          </div>
        </div>
      </aside>
    </section>

    @if($hasLocation || $hasCoords)
      <section class="event-mapcard">
        <div class="event-card event-mapcard__head">
          <h2 class="event-card__title">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <span>Localização</span>
          </h2>

          @if($hasLocation)
            <p class="event-locationline">
              <i class="bi bi-pin-map" aria-hidden="true"></i>
              <span>{{ $event->location_text }}</span>
            </p>
          @endif
        </div>

        @if($hasCoords)
          <div
            id="eventShowMap"
            class="event-map"
            data-lat="{{ $event->lat }}"
            data-lng="{{ $event->lng }}"
            data-title="{{ $event->title }}"
            data-location="{{ $event->location_text }}"
          ></div>
        @endif
      </section>
    @endif

  </section>
</main>

@endsection

@push('scripts')
  <script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    crossorigin=""
  ></script>

  @vite('resources/js/events-show.js')
@endpush