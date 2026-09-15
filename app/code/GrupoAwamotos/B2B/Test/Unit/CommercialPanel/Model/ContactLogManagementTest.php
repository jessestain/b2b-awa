<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\CommercialPanel\Model;

use GrupoAwamotos\B2B\CommercialPanel\Model\ContactLog;
use GrupoAwamotos\B2B\CommercialPanel\Model\ContactLogFactory;
use GrupoAwamotos\B2B\CommercialPanel\Model\ContactLogManagement;
use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\Helper\CurrentAttendant;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\CommercialPanel\Model\ContactLogManagement
 */
class ContactLogManagementTest extends TestCase
{
    private PortfolioScopeInterface&MockObject $portfolioScope;
    private CurrentAttendant&MockObject $currentAttendant;
    private ContactLogFactory&MockObject $contactLogFactory;
    private MockObject $contactLogResource;
    private ContactLogManagement $service;

    protected function setUp(): void
    {
        $this->portfolioScope = $this->createMock(PortfolioScopeInterface::class);
        $this->currentAttendant = $this->createMock(CurrentAttendant::class);
        $this->contactLogFactory = $this->createMock(ContactLogFactory::class);
        $this->contactLogResource = $this->createMock(
            \GrupoAwamotos\B2B\CommercialPanel\Model\ResourceModel\ContactLogResource::class
        );

        $this->service = new ContactLogManagement(
            $this->portfolioScope,
            $this->currentAttendant,
            $this->contactLogFactory,
            $this->contactLogResource
        );
    }

    public function testRegisterContactSuccess(): void
    {
        $this->portfolioScope->method('canAccessCustomer')->with(42)->willReturn(true);
        $this->currentAttendant->method('getId')->willReturn(7);
        $this->currentAttendant->method('getIdForCustomer')->with(42)->willReturn(7);

        $contactLog = $this->createMock(ContactLog::class);
        $contactLog->method('setCustomerId')->willReturnSelf();
        $contactLog->method('setAttendantId')->willReturnSelf();
        $contactLog->method('setAdminUserId')->willReturnSelf();
        $contactLog->method('setContactType')->willReturnSelf();
        $contactLog->method('setObservation')->willReturnSelf();
        $contactLog->method('setNextAction')->willReturnSelf();
        $contactLog->method('setNextActionAt')->willReturnSelf();
        $contactLog->method('getCustomerId')->willReturn(42);
        $contactLog->method('getContactType')->willReturn('whatsapp');
        $contactLog->method('getAttendantId')->willReturn(7);
        $contactLog->method('getAdminUserId')->willReturn(99);
        $this->contactLogFactory->method('create')->willReturn($contactLog);
        $this->contactLogResource->expects($this->once())->method('save')->with($contactLog);

        $result = $this->service->registerContact([
            'customer_id' => 42,
            'contact_type' => 'whatsapp',
            'observation' => 'Cliente pediu retorno amanhã.',
            'next_action' => 'Ligar para fechar pedido',
            'next_action_at' => '2026-05-20 10:00:00',
        ], 99);

        $this->assertSame(42, $result->getCustomerId());
        $this->assertSame('whatsapp', $result->getContactType());
        $this->assertSame(7, $result->getAttendantId());
        $this->assertSame(99, $result->getAdminUserId());
    }

    public function testRegisterContactUsesPortfolioAttendantWhenAdminIsNotSeller(): void
    {
        $this->portfolioScope->method('canAccessCustomer')->with(42)->willReturn(true);
        $this->currentAttendant->method('getId')->willReturn(null);
        $this->currentAttendant->method('getIdForCustomer')->with(42)->willReturn(8);

        $contactLog = $this->createMock(ContactLog::class);
        $contactLog->method('setCustomerId')->willReturnSelf();
        $contactLog->method('setAttendantId')->with(8)->willReturnSelf();
        $contactLog->method('setAdminUserId')->willReturnSelf();
        $contactLog->method('setContactType')->willReturnSelf();
        $contactLog->method('setObservation')->willReturnSelf();
        $contactLog->method('setNextAction')->willReturnSelf();
        $contactLog->method('setNextActionAt')->willReturnSelf();
        $contactLog->method('getCustomerId')->willReturn(42);
        $contactLog->method('getContactType')->willReturn('whatsapp');
        $contactLog->method('getAttendantId')->willReturn(8);
        $contactLog->method('getAdminUserId')->willReturn(1);
        $this->contactLogFactory->method('create')->willReturn($contactLog);
        $this->contactLogResource->expects($this->once())->method('save')->with($contactLog);

        $result = $this->service->registerContact([
            'customer_id' => 42,
            'contact_type' => 'whatsapp',
            'observation' => 'Contato registrado pela supervisão.',
        ], 1);

        $this->assertSame(8, $result->getAttendantId());
    }

    public function testRegisterContactDeniedOutsidePortfolio(): void
    {
        $this->portfolioScope->method('canAccessCustomer')->with(42)->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Cliente fora da sua carteira comercial.');

        $this->service->registerContact([
            'customer_id' => 42,
            'contact_type' => 'phone',
            'observation' => 'Teste',
        ], 1);
    }

    public function testRegisterContactRequiresObservation(): void
    {
        $this->portfolioScope->method('canAccessCustomer')->willReturn(true);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Informe a observação do contato.');

        $this->service->registerContact([
            'customer_id' => 42,
            'contact_type' => 'email',
            'observation' => '   ',
        ], 1);
    }
}
