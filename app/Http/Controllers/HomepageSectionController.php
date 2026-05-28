<?php

namespace App\Http\Controllers;

use App\Models\HomepageSection;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Support\MediaUrl;

class HomepageSectionController extends Controller
{
    private array $allowedTypes = [
        'hero_banner',
        'collection_tiles',
        'bannered_collections',
        'new_arrivals',
        'category_tabs',
        'instagram_reels',
    ];

    public function publicIndex()
    {
        $sections = HomepageSection::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sections,
        ]);
    }

    public function index()
    {
        $sections = HomepageSection::orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sections,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateSection($request);
        $validated['image'] = $this->resolveImage($request, 'image', $validated['title'] ?? $validated['type']);

        $section = HomepageSection::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Homepage section created successfully',
            'data' => $section,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $section = HomepageSection::findOrFail($id);
        $validated = $this->validateSection($request, true);

        if ($request->boolean('remove_image')) {
            $this->deleteImage($section->image);
            $validated['image'] = null;
        }

        if ($request->hasFile('image') || (is_string($request->input('image')) && trim($request->input('image')) !== '')) {
            $this->deleteImage($section->image);
            $validated['image'] = $this->resolveImage($request, 'image', $validated['title'] ?? $section->title ?? $section->type);
        }

        $section->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Homepage section updated successfully',
            'data' => $section->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $section = HomepageSection::findOrFail($id);
        $this->deleteImage($section->image);
        $section->delete();

        return response()->json([
            'success' => true,
            'message' => 'Homepage section deleted successfully',
        ]);
    }

    private function validateSection(Request $request, bool $updating = false): array
    {
        $rules = [
            'type' => ($updating ? 'sometimes|required' : 'required') . '|in:' . implode(',', $this->allowedTypes),
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:1000',
            'image' => $request->hasFile('image') ? 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120' : 'nullable|string|max:2048',
            'link_url' => 'nullable|string|max:2048',
            'button_text' => 'nullable|string|max:100',
            'settings' => 'nullable',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422));
        }

        $data = $validator->validated();

        if ($request->has('settings')) {
            $settings = $request->input('settings');
            if (is_string($settings)) {
                $decoded = json_decode($settings, true);
                $data['settings'] = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
            } elseif (is_array($settings)) {
                $data['settings'] = $settings;
            } else {
                $data['settings'] = [];
            }
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        if (!array_key_exists('sort_order', $data)) {
            $data['sort_order'] = 0;
        }

        return $data;
    }

    private function resolveImage(Request $request, string $field, string $title): ?string
    {
        if ($request->hasFile($field)) {
            $image = $request->file($field);
            $imageName = time() . '_' . Str::slug($title) . '.' . $image->getClientOriginalExtension();
            return $image->storeAs('homepage', $imageName, 'public');
        }

        $value = $request->input($field);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    private function deleteImage(?string $path): void
    {
        $storedPath = MediaUrl::storedPath($path);
        if ($storedPath) {
            Storage::disk('public')->delete($storedPath);
        }
    }
}
