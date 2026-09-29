<?php

namespace Modules\Discourse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Discourse\Models\Guest;

class GuestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Guest::query();

        // سرچ بر اساس نام کامل
        if ($request->filled('search')) {
            $query->where('full_name', 'LIKE', '%' . $request->search . '%');
        }

        // اگر درخواست از نوع سرچ لحظه‌ای بود (برای dropdown)
        if ($request->filled('search') && $request->has('for_select')) {
            return response()->json([
                'data' => $query->select('id', 'full_name', 'avatar', 'position')
                    ->limit(20)
                    ->get()
            ]);
        }

        // حالت معمولی با pagination
        return response()->json(
            $query->latest()->paginate($request->per_page ?? 15)
        );
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'avatar' => ['required', 'file', 'max:1024'],
            'position' => ['required', 'string', 'max:255'],
            'biography' => ['required', 'string'],
        ]);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('guest', 'public');
            $data['avatar'] = $path;
        }

        $guest = Guest::create($data);

        return response()->json($guest, 201);
    }

    /**
     * Show the specified resource.
     */
    public function show(Guest $guest)
    {
        return response()->json($guest);
    }
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Guest $guest)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'avatar' => ['nullable', 'file', 'max:1024'],
            'position' => ['required', 'string', 'max:255'],
            'biography' => ['required', 'string'],
        ]);

        if ($request->hasFile('avatar')) {
            if ($guest->avatar) {
                Storage::disk('public')->delete($guest->avatar);
            }
            $path = $request->file('avatar')->store('guest', 'public');
            $data['avatar'] = $path;
        }

        $guest->update($data);

        return response()->json($guest);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Guest $guest)
    {
        if ($guest->avatar) {
            Storage::disk('public')->delete($guest->avatar);
        }

        $guest->delete();

        return response()->json([
            'message' => 'Guest deleted successfully.'
        ]);
    }

    /**
     * Get front list of guests.
     */
    public function getFrontGuests()
    {
        return response()->json([
            'data' => Guest::latest()->get()
        ]);
    }

    /**
     * Get front detail of a guest by id.
     */
    public function getFrontDetailGuest($id)
    {
        $guest = Guest::find($id);

        if (! $guest) {
            return response()->json([
                'message' => 'هیچ مهمانی یافت نشد.'
            ], 404);
        }

        return response()->json([
            'guest' => $guest,
        ]);
    }
}
