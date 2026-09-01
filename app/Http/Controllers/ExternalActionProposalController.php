<?php

namespace App\Http\Controllers;

use App\Exceptions\CalendarGatewayException;
use App\Exceptions\GoogleGatewayException;
use App\Exceptions\IntegrationGatewayException;
use App\Models\ExternalActionProposal;
use App\Services\ExternalActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ExternalActionProposalController extends Controller
{
    public function confirm(
        Request $request,
        ExternalActionProposal $externalActionProposal,
        ExternalActionService $actions,
    ): JsonResponse {
        $this->authorize('confirm', $externalActionProposal);

        try {
            $proposal = $actions->confirm($externalActionProposal);
        } catch (CalendarGatewayException|GoogleGatewayException|IntegrationGatewayException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Il servizio esterno non ha completato l’azione: '.$exception->getMessage().'.',
            ], 502);
        } catch (RuntimeException $exception) {
            if ($externalActionProposal->refresh()->status->value === 'failed') {
                return response()->json([
                    'message' => 'Il servizio esterno non ha completato l’azione. Puoi riprovare.',
                ], 502);
            }

            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Il servizio esterno non ha completato l’azione. Puoi riprovare.',
            ], 502);
        }

        return response()->json([
            'message' => 'Azione completata su '.$proposal->type->providerLabel().'.',
            'status' => $proposal->status->value,
            'result' => $proposal->result,
        ]);
    }

    public function reject(
        Request $request,
        ExternalActionProposal $externalActionProposal,
        ExternalActionService $actions,
    ): JsonResponse {
        $this->authorize('reject', $externalActionProposal);

        try {
            $proposal = $actions->reject($externalActionProposal);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Proposta rifiutata.',
            'status' => $proposal->status->value,
        ]);
    }
}
