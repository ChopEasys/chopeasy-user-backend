<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\ImageKitHelper;
use App\Models\Slide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlideController extends Controller
{
    /**
     * Get all slides
     */
    public function index(): JsonResponse
    {
        $slides = Slide::orderBy('order')->orderBy('created_at', 'desc')->get();
        return response()->json(['data' => $slides]);
    }

    /**
     * Get active slides for frontend carousel
     */
    public function active(): JsonResponse
    {
        $slides = Slide::where('is_active', true)
            ->orderBy('order')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($slide) {
                return [
                    'id' => $slide->id,
                    'title' => $slide->title,
                    'description' => $slide->description,
                    'button_text' => $slide->button_text,
                    'layout' => $slide->layout,
                    'background_color' => $slide->background_color,
                    'image_url' => $slide->image_path, // ImageKit returns full URL
                    'type' => $slide->type,
                    'url' => $slide->url,
                ];
            });

        return response()->json(['data' => $slides]);
    }

    /**
     * Show a specific slide
     */
    public function show($id): JsonResponse
    {
        $slide = Slide::findOrFail($id);
        return response()->json(['data' => $slide]);
    }

    /**
     * Store a new slide
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'button_text' => 'nullable|string|max:80',
            'layout' => 'nullable|in:split,image_top,text_only',
            'background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'image' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
            'type' => 'required|in:customer,vendor,rider',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable',
            'url' => 'nullable|url',
        ]);

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imageUrl = ImageKitHelper::uploadFile(
                $request->file('image'),
                'slide_' . time() . '_' . str_replace(' ', '_', $request->title ?? 'unnamed')
            );

            if (!$imageUrl) {
                return response()->json([
                    'message' => 'Failed to upload image to ImageKit'
                ], 500);
            }
        }

        $slide = Slide::create([
            'title' => $request->title,
            'description' => $request->description,
            'button_text' => $request->button_text,
            'layout' => $request->input('layout', 'text_only'),
            'background_color' => $request->input('background_color', '#FFC107'),
            'image_path' => $imageUrl,
            'type' => $request->type,
            'order' => $request->order ?? 0,
            'is_active' => $request->is_active ?? 1,
            'url' => $request->url,
        ]);

        return response()->json(['data' => $slide], 201);
    }

    /**
     * Update a slide
     */
    public function update(Request $request, $id): JsonResponse
    {
        $slide = Slide::findOrFail($id);

        $request->validate([
            'title' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'button_text' => 'sometimes|nullable|string|max:80',
            'layout' => 'sometimes|required|in:split,image_top,text_only',
            'background_color' => ['sometimes', 'required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'image' => 'sometimes|nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
 
            'type' => 'sometimes|required|in:customer,vendor,rider',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'url' => 'nullable|url',
        ]);

        if ($request->hasFile('image')) {
            // Upload new image to ImageKit
            $imageUrl = ImageKitHelper::uploadFile(
                $request->file('image'),
                'slide_' . time() . '_' . str_replace(' ', '_', $request->title ?? 'unnamed')
            );

            if (!$imageUrl) {
                return response()->json([
                    'message' => 'Failed to upload image to ImageKit'
                ], 500);
            }

            $slide->image_path = $imageUrl;
        }

        $slide->update($request->only(['title', 'description', 'button_text', 'layout', 'background_color', 'type', 'order', 'is_active', 'url']));

        return response()->json(['data' => $slide]);
    }

    /**
     * Delete a slide
     */
    public function destroy($id): JsonResponse
    {
        $slide = Slide::findOrFail($id);
        $slide->delete();

        return response()->json(['message' => 'Slide deleted successfully']);
    }
}
