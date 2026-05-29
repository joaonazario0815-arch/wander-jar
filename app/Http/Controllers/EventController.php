<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EventController extends Controller
{
    private function dateOnly($value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function normalizeTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function validateFutureEventDateTime(Request $request, ?string $date, ?string $time): void
    {
        if (!$date) {
            return;
        }

        if ($request->filled('event_time') && !$time) {
            throw ValidationException::withMessages([
                'event_time' => 'A hora do evento não é válida.',
            ]);
        }

        $today = now()->toDateString();

        if ($date < $today) {
            throw ValidationException::withMessages([
                'event_date' => 'A data do evento não pode ser anterior ao dia de hoje.',
            ]);
        }

        if ($date === $today && $time) {
            $eventDateTime = Carbon::parse($date . ' ' . $time);

            if ($eventDateTime->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'event_time' => 'Para eventos criados hoje, a hora tem de ser posterior à hora atual.',
                ]);
            }
        }
    }

    private function eventIsPast(Event $event): bool
    {
        $date = $this->dateOnly($event->event_date);

        if (!$date) {
            return false;
        }

        $today = now()->toDateString();

        if ($date < $today) {
            return true;
        }

        if ($date > $today) {
            return false;
        }

        $time = $this->normalizeTime($event->event_time);

        if (!$time) {
            return false;
        }

        $eventDateTime = Carbon::parse($date . ' ' . $time);

        return $eventDateTime->isPast();
    }

    private function eventIsActive(Event $event): bool
    {
        if ($this->eventIsPast($event)) {
            return false;
        }

        if (Schema::hasColumn('events', 'is_active')) {
            return (bool) $event->is_active;
        }

        return true;
    }

    private function participationPayload(Event $event, string $message, bool $isJoined): array
    {
        $event->loadCount('participants');

        $user = auth()->user();

        return [
            'message' => $message,
            'participants_count' => (int) $event->participants_count,
            'max_participants' => (int) $event->max_participants,
            'is_joined' => $isJoined,
            'is_creator' => $user ? $event->user_id === $user->id : false,
            'is_active' => $this->eventIsActive($event),
        ];
    }

    private function wantsJson(): bool
    {
        return request()->expectsJson() || request()->ajax();
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Event::class);

        $query = Event::query()
            ->with(['creator'])
            ->orderBy('event_date', 'asc')
            ->orderBy('event_time', 'asc');

        $today = now()->toDateString();
        $nowTime = now()->format('H:i:s');

        $query->where(function ($w) use ($today, $nowTime) {
            $w->whereDate('event_date', '>', $today)
                ->orWhere(function ($w2) use ($today, $nowTime) {
                    $w2->whereDate('event_date', '=', $today)
                        ->where(function ($w3) use ($nowTime) {
                            $w3->whereNull('event_time')
                                ->orWhere('event_time', '>=', $nowTime);
                        });
                });
        });

        if ($request->filled('q')) {
            $q = trim((string) $request->q);

            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                    ->orWhere('location_text', 'like', "%{$q}%");
            });
        }

        if ($request->filled('date_scope')) {
            $scope = $request->date_scope;

            if ($scope === 'today') {
                $query->whereDate('event_date', now()->toDateString());
            }

            if ($scope === 'next7') {
                $query->whereBetween('event_date', [
                    now()->toDateString(),
                    now()->addDays(7)->toDateString(),
                ]);
            }
        }

        $events = $query->paginate(12)->withQueryString();

        return view('events.index', compact('events'));
    }

    public function create(): View
    {
        $this->authorize('create', Event::class);

        return view('events.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Event::class);

        $data = $request->validate([
            'title'            => ['required', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'location_text'    => ['nullable', 'string', 'max:255'],
            'lat'              => ['nullable', 'numeric'],
            'lng'              => ['nullable', 'numeric'],
            'event_date'       => ['required', 'date', 'after_or_equal:today'],
            'event_time'       => ['nullable'],
            'max_participants' => ['required', 'integer', 'min:1', 'max:999'],
        ], [
            'event_date.after_or_equal' => 'A data do evento não pode ser anterior ao dia de hoje.',
            'event_date.required'       => 'A data do evento é obrigatória.',
            'title.required'            => 'O título do evento é obrigatório.',
            'max_participants.required' => 'O limite de participantes é obrigatório.',
            'max_participants.min'      => 'O evento tem de permitir pelo menos 1 participante.',
        ]);

        $data['event_date'] = $this->dateOnly($data['event_date']);
        $data['event_time'] = $this->normalizeTime($data['event_time'] ?? null);

        $this->validateFutureEventDateTime($request, $data['event_date'], $data['event_time']);

        $event = Event::create([
            'user_id'          => auth()->id(),
            'title'            => $data['title'],
            'description'      => $data['description'] ?? null,
            'location_text'    => $data['location_text'] ?? null,
            'lat'              => $data['lat'] ?? null,
            'lng'              => $data['lng'] ?? null,
            'event_date'       => $data['event_date'],
            'event_time'       => $data['event_time'],
            'max_participants' => $data['max_participants'],
        ]);

        return redirect()
            ->route('events.show', $event)
            ->with('status', 'Evento criado com sucesso!');
    }

    public function show(Event $event): View
    {
        $this->authorize('view', $event);

        $event->load(['creator', 'participants']);

        $user = auth()->user();

        $isCreator = $user ? $event->user_id === $user->id : false;
        $isJoined = $user ? $event->participants->contains($user->id) : false;
        $isActive = $this->eventIsActive($event);
        $participantsCount = $event->participants->count();

        return view('events.show', compact(
            'event',
            'isCreator',
            'isJoined',
            'isActive',
            'participantsCount'
        ));
    }

    public function edit(Event $event): View
    {
        $this->authorize('update', $event);

        return view('events.edit', compact('event'));
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'title'            => ['required', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'location_text'    => ['nullable', 'string', 'max:255'],
            'lat'              => ['nullable', 'numeric'],
            'lng'              => ['nullable', 'numeric'],
            'event_date'       => ['required', 'date', 'after_or_equal:today'],
            'event_time'       => ['nullable'],
            'max_participants' => ['required', 'integer', 'min:1', 'max:999'],
        ], [
            'event_date.after_or_equal' => 'A data do evento não pode ser anterior ao dia de hoje.',
            'event_date.required'       => 'A data do evento é obrigatória.',
            'title.required'            => 'O título do evento é obrigatório.',
            'max_participants.required' => 'O limite de participantes é obrigatório.',
            'max_participants.min'      => 'O evento tem de permitir pelo menos 1 participante.',
        ]);

        $data['event_date'] = $this->dateOnly($data['event_date']);
        $data['event_time'] = $this->normalizeTime($data['event_time'] ?? null);

        $this->validateFutureEventDateTime($request, $data['event_date'], $data['event_time']);

        $event->update([
            'title'            => $data['title'],
            'description'      => $data['description'] ?? null,
            'location_text'    => $data['location_text'] ?? null,
            'lat'              => $data['lat'] ?? null,
            'lng'              => $data['lng'] ?? null,
            'event_date'       => $data['event_date'],
            'event_time'       => $data['event_time'],
            'max_participants' => $data['max_participants'],
        ]);

        return redirect()
            ->route('events.show', $event)
            ->with('status', 'Evento atualizado com sucesso!');
    }

    public function join(Event $event): RedirectResponse|JsonResponse
    {
        $this->authorize('join', $event);

        $user = auth()->user();

        if (!$user) {
            if ($this->wantsJson()) {
                return response()->json([
                    'message' => 'Precisas de iniciar sessão para participar neste evento.',
                ], 401);
            }

            return redirect()->route('login');
        }

        if (!$this->eventIsActive($event)) {
            $message = 'Este evento já não está disponível.';

            if ($this->wantsJson()) {
                return response()->json([
                    'message' => $message,
                ], 422);
            }

            return back()->with('status', $message);
        }

        if ($event->participants()->where('users.id', $user->id)->exists()) {
            $message = 'Já estás inscrito neste evento.';

            if ($this->wantsJson()) {
                return response()->json(
                    $this->participationPayload($event, $message, true)
                );
            }

            return back()->with('status', $message);
        }

        $participantsCount = $event->participants()->count();

        if ($participantsCount >= (int) $event->max_participants) {
            $message = 'O evento está cheio.';

            if ($this->wantsJson()) {
                return response()->json([
                    'message' => $message,
                ], 422);
            }

            return back()->with('status', $message);
        }

        $event->participants()->syncWithoutDetaching([$user->id]);

        $message = 'Inscrição feita com sucesso!';

        if ($this->wantsJson()) {
            return response()->json(
                $this->participationPayload($event, $message, true)
            );
        }

        return back()->with('status', $message);
    }

    public function leave(Event $event): RedirectResponse|JsonResponse
    {
        $this->authorize('leave', $event);

        $user = auth()->user();

        if (!$user) {
            if ($this->wantsJson()) {
                return response()->json([
                    'message' => 'Precisas de iniciar sessão.',
                ], 401);
            }

            return redirect()->route('login');
        }

        if (!$this->eventIsActive($event)) {
            $message = 'Este evento já não está disponível.';

            if ($this->wantsJson()) {
                return response()->json([
                    'message' => $message,
                ], 422);
            }

            return back()->with('status', $message);
        }

        $isParticipant = $event->participants()->where('users.id', $user->id)->exists();

        if (!$isParticipant) {
            $message = 'Tu não estás inscrito neste evento.';

            if ($this->wantsJson()) {
                return response()->json(
                    $this->participationPayload($event, $message, false)
                );
            }

            return back()->with('status', $message);
        }

        $event->participants()->detach($user->id);

        $message = 'Saíste do evento.';

        if ($this->wantsJson()) {
            return response()->json(
                $this->participationPayload($event, $message, false)
            );
        }

        return back()->with('status', $message);
    }

    public function cancel(Event $event): RedirectResponse
    {
        $this->authorize('cancel', $event);

        if (Schema::hasColumn('events', 'is_active')) {
            $event->update(['is_active' => false]);

            return back()->with('status', 'Evento cancelado.');
        }

        $event->delete();

        return redirect()
            ->route('events.index')
            ->with('status', 'Evento removido.');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $this->authorize('delete', $event);

        $event->delete();

        return redirect()
            ->route('events.index')
            ->with('status', 'Evento removido.');
    }
}