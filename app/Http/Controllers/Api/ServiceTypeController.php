<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceTypeRequest;
use App\Http\Resources\ServiceTypeResource;
use App\Models\ServiceType;
use App\Support\Sector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ServiceTypeController — catálogo de prestaciones.
 *
 *   GET   /service-types                 → listar (?include_inactive=1 solo admin)
 *   POST  /service-types                 → alta [solo admin]
 *   PATCH /service-types/{serviceType}   → editar / marcar obligatoria / desactivar [solo admin]
 *
 * No hay DELETE: una prestación con historial no se borra, se desactiva.
 * El listado incluye el catálogo de sectores en 'sectors' (para los desplegables).
 */
class ServiceTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless(
            $user->can('ninos.gestionar') || $user->can('reportes.ver') || $user->hasRole('admin'),
            403,
            'No tiene permiso para ver el catálogo de prestaciones.'
        );

        $includeInactive = $user->hasRole('admin') && $request->boolean('include_inactive');

        $types = ServiceType::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->when($user->hasRole('admin'), fn ($q) => $q->withCount('childServices'))
            ->orderBy('sector')
            ->orderBy('name')
            ->get();

        return ServiceTypeResource::collection($types)->additional([
            'sectors' => Sector::options(),
        ]);
    }

    public function store(ServiceTypeRequest $request): JsonResponse
    {
        $type = ServiceType::create([
            ...$request->validated(),
            'created_by' => $request->user()->auditId(),
        ]);

        return response()->json(new ServiceTypeResource($type), 201);
    }

    public function update(ServiceTypeRequest $request, ServiceType $serviceType): JsonResponse
    {
        $serviceType->update([
            ...$request->validated(),
            'updated_by' => $request->user()->auditId(),
        ]);

        return response()->json(new ServiceTypeResource($serviceType));
    }
}
