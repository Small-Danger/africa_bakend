<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Roles;
use App\Http\Controllers\Controller;
use App\Models\ShopSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();
        $settings = ShopSetting::current();

        return response()->json([
            'success' => true,
            'message' => 'Paramètres récupérés avec succès',
            'data' => $this->payload($settings, $actor),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $request->user();
        $allowed = $this->editableKeys($actor);
        $input = $request->only($allowed);

        if ($input === []) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune donnée à enregistrer',
            ], 422);
        }

        $rules = [];
        $messages = [
            'preorder_delay_days.min' => 'Le délai de précommande doit être d\'au moins 1 jour',
            'preorder_delay_days.max' => 'Le délai de précommande ne peut pas dépasser 90 jours',
            'unpaid_expiry_hours.min' => 'L\'expiration doit durer au moins 1 heure',
            'unpaid_expiry_hours.max' => 'L\'expiration ne peut pas dépasser 168 heures (7 jours)',
            'low_stock_threshold.max' => 'Le seuil de stock faible ne peut pas dépasser 999',
            'min_deposit_percent.max' => 'L\'acompte doit être entre 0 et 100 %',
            'payment_methods.required' => 'Choisissez au moins un mode de paiement',
            'payment_methods.min' => 'Choisissez au moins un mode de paiement',
        ];

        if (array_key_exists('preorder_delay_days', $input)) {
            $rules['preorder_delay_days'] = 'required|integer|min:1|max:90';
        }
        if (array_key_exists('unpaid_expiry_hours', $input)) {
            $rules['unpaid_expiry_hours'] = 'required|integer|min:1|max:168';
        }
        if (array_key_exists('low_stock_threshold', $input)) {
            $rules['low_stock_threshold'] = 'required|integer|min:0|max:999';
        }
        if (array_key_exists('min_deposit_percent', $input)) {
            $rules['min_deposit_percent'] = 'required|integer|min:0|max:100';
        }
        if (array_key_exists('payment_methods', $input)) {
            $rules['payment_methods'] = 'required|array|min:1';
            $rules['payment_methods.*'] = ['required', 'string', Rule::in(ShopSetting::paymentMethodKeys())];
        }
        if (array_key_exists('whatsapp_number', $input)) {
            $rules['whatsapp_number'] = 'nullable|string|max:30';
        }
        if (array_key_exists('notify_whatsapp', $input)) {
            $rules['notify_whatsapp'] = 'required|boolean';
        }
        if (array_key_exists('notify_email', $input)) {
            $rules['notify_email'] = 'required|boolean';
        }

        $validator = Validator::make($input, $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $settings = ShopSetting::current();
        $settings->fill($validator->validated());
        $settings->save();

        return response()->json([
            'success' => true,
            'message' => 'Paramètres enregistrés',
            'data' => $this->payload($settings->fresh(), $actor),
        ]);
    }

    /**
     * @return list<string>
     */
    private function editableKeys(User $actor): array
    {
        $keys = ShopSetting::operationalKeys();

        if ($actor->hasRole(Roles::ADMIN)) {
            return array_merge($keys, ShopSetting::identityKeys());
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ShopSetting $settings, User $actor): array
    {
        $labels = ShopSetting::paymentMethodLabels();

        return [
            'settings' => $settings->toPayload(),
            'can_edit_identity' => $actor->hasRole(Roles::ADMIN),
            'payment_method_options' => collect($labels)->map(fn (string $label, string $name) => [
                'name' => $name,
                'label' => $label,
            ])->values(),
        ];
    }
}
