<?php
declare(strict_types=1);
namespace Tests;

use NeoFramework\Core\Abstract\Controller;
use NeoFramework\Core\Attributes\Email;
use NeoFramework\Core\Attributes\Length;
use NeoFramework\Core\Attributes\ListOf;
use NeoFramework\Core\Attributes\Route;
use NeoFramework\Core\Response;
use NeoFramework\Core\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final readonly class CreateDtoFixture { public function __construct(#[Email] public string $email, #[Length(min: 8)] public string $password, public int $age) {} }
final readonly class AddressDtoFixture { public function __construct(#[Email] public string $email, public \DateTimeImmutable $birthday) {} }
final readonly class TagDtoFixture { public function __construct(#[Length(min: 2)] public string $name) {} }
final readonly class CreateProjectDtoFixture {
    /** @param list<TagDtoFixture> $tags @param list<int> $scores */
    public function __construct(
        public AddressDtoFixture $owner,
        #[ListOf(TagDtoFixture::class)] public array $tags,
        #[ListOf('int')] public array $scores,
        public \DateTimeImmutable $scheduledAt,
    ) {}
}
final class DtoControllerFixture extends Controller { #[Route('/dto', ['POST'], false)] public function store(CreateDtoFixture $input): Response { return $this->json(['email' => $input->email, 'age' => $input->age]); } }
final class NestedDtoControllerFixture extends Controller {
    #[Route('/projects', ['POST'], false)]
    public function store(CreateProjectDtoFixture $input): Response {
        return $this->json([
            'owner' => $input->owner->email,
            'tag' => $input->tags[0]->name,
            'score' => $input->scores[0],
            'scheduledAt' => $input->scheduledAt->format(\DATE_ATOM),
        ]);
    }
}
final class NeoFrameworkDtoBindingTest extends TestCase {
    public function testBindsAndCastsReadonlyDto(): void { TestClient::forControllers([DtoControllerFixture::class])->postJson('/dto', ['email' => 'neo@example.com', 'password' => 'secreta8', 'age' => '18'])->assertOk()->assertJsonPath('age', 18); }
    public function testInvalidDtoNeverReachesController(): void { TestClient::forControllers([DtoControllerFixture::class])->postJson('/dto', ['email' => 'invalid', 'password' => 'short', 'age' => 'x'])->assertStatus(422)->assertContentType('application/problem+json')->assertJsonPath('errors.email.0', 'Must be a valid email address.'); }
    public function testBindsNestedDtosTypedListsAndDates(): void {
        TestClient::forControllers([NestedDtoControllerFixture::class])
            ->postJson('/projects', [
                'owner' => ['email' => 'owner@example.com', 'birthday' => '2000-02-29'],
                'tags' => [['name' => 'php']], 'scores' => ['7'],
                'scheduledAt' => '2026-08-25T14:30:00+00:00',
            ])
            ->assertOk()
            ->assertJsonPath('owner', 'owner@example.com')
            ->assertJsonPath('tag', 'php')
            ->assertJsonPath('score', 7)
            ->assertJsonPath('scheduledAt', '2026-08-25T14:30:00+00:00');
    }
    public function testNestedAndListValidationErrorsUseFullIndexedPaths(): void {
        $response = TestClient::forControllers([NestedDtoControllerFixture::class])
            ->postJson('/projects', [
                'owner' => ['email' => 'invalid', 'birthday' => 'not-a-date'],
                'tags' => [['name' => 'x']], 'scores' => ['nope'], 'scheduledAt' => 'invalid',
            ]);

        $response->assertStatus(422);
        $errors = $response->json()['errors'];
        self::assertSame(['Must be a valid email address.'], $errors['owner.email']);
        self::assertSame(['Invalid value.'], $errors['owner.birthday']);
        self::assertSame(['Length is invalid.'], $errors['tags.0.name']);
        self::assertSame(['Invalid value.'], $errors['scores.0']);
        self::assertSame(['Invalid value.'], $errors['scheduledAt']);
    }
}
