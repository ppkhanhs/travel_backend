<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\ChatController;
use App\Services\AutoPromotionService;
use App\Services\ChatbotService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ChatControllerTest extends TestCase
{
    /**
     * Helper to invoke private/protected methods for lightweight unit tests.
     */
    private function invokeMethod(object $object, string $method, array $params = [])
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $params);
    }

    public function testNormalizeLanguageVi(): void
    {
        $controller = new ChatController(
            $this->createMock(ChatbotService::class),
            $this->createMock(AutoPromotionService::class)
        );

        $result = $this->invokeMethod($controller, 'normalizeLanguage', ['VI_vn']);
        $this->assertSame('vi', $result);
    }

    public function testNormalizeLanguageEn(): void
    {
        $controller = new ChatController(
            $this->createMock(ChatbotService::class),
            $this->createMock(AutoPromotionService::class)
        );

        $result = $this->invokeMethod($controller, 'normalizeLanguage', ['English']);
        $this->assertSame('en', $result);
    }

    public function testNormalizeLanguageDefault(): void
    {
        $controller = new ChatController(
            $this->createMock(ChatbotService::class),
            $this->createMock(AutoPromotionService::class)
        );

        $result = $this->invokeMethod($controller, 'normalizeLanguage', ['jp']);
        $this->assertSame('vi', $result, 'Mặc định trả về vi nếu ngôn ngữ không khớp.');
    }
}
