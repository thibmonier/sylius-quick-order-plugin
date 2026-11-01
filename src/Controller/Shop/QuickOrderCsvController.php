<?php

declare(strict_types=1);

namespace Tmonier\SyliusQuickOrderPlugin\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tmonier\SyliusQuickOrderPlugin\Adder\BulkAddResult;
use Tmonier\SyliusQuickOrderPlugin\Report\CsvWriter;

final class QuickOrderCsvController extends AbstractController
{
    public function __construct(
        private readonly CsvWriter $csvWriter,
        private readonly QuickOrderSessionStorage $sessionStorage,
        private readonly bool $allowGuests,
    ) {
    }

    public function templateAction(): Response
    {
        return $this->csvResponse($this->csvWriter->template(), 'quick-order-template.csv');
    }

    public function reportAction(Request $request): Response
    {
        if (($denied = $this->denyGuests()) instanceof Response) {
            return $denied;
        }
        $result = $this->sessionStorage->loadResult($request);
        if (!$result instanceof BulkAddResult) {
            throw new NotFoundHttpException('No quick order result.');
        }

        return $this->csvResponse($this->csvWriter->report($result), 'quick-order-report.csv');
    }

    private function denyGuests(): ?Response
    {
        if ($this->allowGuests || $this->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return null;
        }

        return $this->redirectToRoute('sylius_shop_login');
    }

    private function csvResponse(string $csv, string $filename): Response
    {
        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
