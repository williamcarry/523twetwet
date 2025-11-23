<?php

namespace App\Controller\common;

use App\Service\ExpressTrackingService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/common/express', name: 'api_express_')]
class ExpressTrackingController extends AbstractController
{
    public function __construct(
        private ExpressTrackingService $expressTracking,
        private LoggerInterface $logger
    ) {}

    #[Route('/track', name: 'track', methods: ['GET'])]
    public function track(Request $request): JsonResponse
    {
        $expressNo = $request->query->get('expressNo');
        $mobile = $request->query->get('mobile');

        if (empty($expressNo)) {
            return $this->json([
                'success' => false,
                'message' => '快递单号不能为空'
            ], 400);
        }

        try {
            $tracking = $this->expressTracking->track($expressNo, $mobile);

            if (isset($tracking['error']) && $tracking['error']) {
                return $this->json([
                    'success' => false,
                    'message' => $tracking['message'] ?? '查询失败',
                    'data' => $tracking
                ]);
            }

            return $this->json([
                'success' => true,
                'data' => $tracking
            ]);

        } catch (\Exception $e) {
            $this->logger->error('快递查询失败', [
                'expressNo' => $expressNo,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/batch-track', name: 'batch_track', methods: ['POST'])]
    public function batchTrack(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $expressNos = $data['expressNos'] ?? [];

        if (empty($expressNos) || !is_array($expressNos)) {
            return $this->json([
                'success' => false,
                'message' => '快递单号列表不能为空'
            ], 400);
        }

        try {
            $results = $this->expressTracking->batchTrack($expressNos);

            return $this->json([
                'success' => true,
                'data' => $results
            ]);

        } catch (\Exception $e) {
            $this->logger->error('批量快递查询失败', [
                'expressNos' => $expressNos,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
