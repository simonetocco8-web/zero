<?php

namespace App\Http\Controllers;

use App\Actions\SaveCategory;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Category::class);

        return view('categories.index', ['categories' => Category::orderBy('sort_order')->orderBy('name')->paginate(20)]);
    }

    public function create()
    {
        Gate::authorize('create', Category::class);

        return view('categories.form', ['category' => new Category(['is_active' => true, 'sort_order' => 0])]);
    }

    public function store(CategoryRequest $request, SaveCategory $action)
    {
        $action->handle($request->user(), $request->validated());

        return to_route('admin.categories.index')->with('status', 'Categoria salvata.');
    }

    public function edit(Category $category)
    {
        Gate::authorize('update', $category);

        return view('categories.form', compact('category'));
    }

    public function update(CategoryRequest $request, Category $category, SaveCategory $action)
    {
        $action->handle($request->user(), $request->validated(), $category);

        return to_route('admin.categories.index')->with('status', 'Categoria salvata.');
    }

    public function destroy(Request $request, Category $category, SaveCategory $action)
    {
        $action->delete($request->user(), $category);

        return to_route('admin.categories.index')->with('status', 'Categoria eliminata.');
    }
}
