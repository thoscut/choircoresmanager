<?php

declare(strict_types=1);

namespace OCA\ChoirScores\Tests\unit\Controller;

use OCA\ChoirScores\Controller\AdminController;
use OCA\ChoirScores\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminController.
 */
final class AdminControllerTest extends TestCase {
	private ConfigService $configService;
	private IRequest $request;
	private AdminController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createMock(ConfigService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new AdminController(
			'choirscores',
			$this->request,
			$this->configService
		);
	}

	#[TestWith([['admin', 'editors', 'orchestra']])]
	#[TestWith([[]])]
	public function testGetEditGroupsReturnsSettings(array $groups): void {
		$this->configService->expects($this->once())
			->method('getAllowedEditGroups')
			->willReturn($groups);

		$response = $this->controller->getEditGroups();

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertEquals(['editGroups' => $groups], $response->getData());
		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	#[TestWith([['admin', 'musicians']])]
	#[TestWith([[]])]
	public function testPostEditGroupsUpdatesSettings(array $groups): void {
		$this->configService->expects($this->once())
			->method('setAllowedEditGroups')
			->with($groups);

		$this->configService->expects($this->once())
			->method('getAllowedEditGroups')
			->willReturn($groups);

		$response = $this->controller->postEditGroups($groups);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertEquals(['editGroups' => $groups], $response->getData());
		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testGetRepertoireThresholdsReturnsSettings(): void {
		$this->configService->method('getRepertoireThresholds')
			->willReturn(['currentYears' => 3, 'dormantYears' => 10]);

		$response = $this->controller->getRepertoireThresholds();

		$this->assertEquals(['currentYears' => 3, 'dormantYears' => 10], $response->getData());
		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testPostRepertoireThresholdsUpdatesSettings(): void {
		$this->configService->expects($this->once())
			->method('setRepertoireThresholds')
			->with(2, 8);
		$this->configService->method('getRepertoireThresholds')
			->willReturn(['currentYears' => 2, 'dormantYears' => 8]);

		$response = $this->controller->postRepertoireThresholds(2, 8);

		$this->assertEquals(['currentYears' => 2, 'dormantYears' => 8], $response->getData());
	}

	public function testPostRepertoireThresholdsRejectsInvalidValues(): void {
		$this->configService->method('setRepertoireThresholds')
			->willThrowException(new \InvalidArgumentException('invalid'));

		$this->expectException(OCSBadRequestException::class);

		$this->controller->postRepertoireThresholds(10, 3);
	}

	#[TestWith([true])]
	#[TestWith([false])]
	public function testGetPublicPageReturnsSetting(bool $enabled): void {
		$this->configService->method('isPublicPageEnabled')->willReturn($enabled);

		$response = $this->controller->getPublicPage();

		$this->assertEquals(['publicPageEnabled' => $enabled], $response->getData());
	}

	#[TestWith([true])]
	#[TestWith([false])]
	public function testPostPublicPageUpdatesSetting(bool $enabled): void {
		$this->configService->expects($this->once())
			->method('setPublicPageEnabled')
			->with($enabled);
		$this->configService->method('isPublicPageEnabled')->willReturn($enabled);

		$response = $this->controller->postPublicPage($enabled);

		$this->assertEquals(['publicPageEnabled' => $enabled], $response->getData());
	}
}
