<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpItemComponente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpComponenteController extends Controller
{
    public function update(Request $request, Op $op, OpItem $item, OpItemComponente $componente): JsonResponse
    {
        abort_if($componente->op_item_id !== $item->id, 403);

        $data = $request->validate([
            'cantidad'    => 'sometimes|numeric|min:0',
            'observacion' => 'nullable|string|max:500',
        ]);

        $componente->update($data);

        return response()->json([
            'ok'       => true,
            'cantidad' => (float) $componente->cantidad,
        ]);
    }
}
