<?php

namespace Tests\Feature\Account;

use App\Enums\MessageSender;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\AvatarService;
use App\Services\Chat\ChatPresenter;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 10C: profile pictures. JPG, PNG or WEBP up to 2 MB, checked by extension AND the type read from the file, stored
 * on the public disk under avatars/ with a random name; replace deletes the old file, Remove deletes it; shown in the
 * top bar, the account page, the chat and the owner's customer pages, with initials as the fallback; never for walk-ins.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->customer = User::factory()->create(['name' => 'Ana Reyes']);
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Arceo']);
    }

    private function image(string $name = 'me.jpg', string $kind = 'jpeg', int $padTo = 0): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        $path = tempnam(sys_get_temp_dir(), 'av');
        match ($kind) {
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagejpeg($image, $path),
        };
        if ($padTo > 0) {
            file_put_contents($path, str_repeat("\0", max(0, $padTo - filesize($path))), FILE_APPEND);
        }

        return new UploadedFile($path, $name, null, null, true);
    }

    /** A real file with these bytes, so the server reads its actual type (a fake file would report the extension's). */
    private function raw(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function upload(User $user, UploadedFile $file, string $area = 'customer')
    {
        return $this->actingAs($user)->post(route($area.'.account.avatar'), ['avatar' => $file]);
    }

    public function test_a_customer_uploads_a_picture_with_a_random_name_and_sees_it_everywhere(): void
    {
        $this->upload($this->customer, $this->image('My Selfie.jpg'))
            ->assertRedirect(route('customer.account.show').'#profile')->assertSessionHas('status', 'Profile picture saved.');

        $path = $this->customer->fresh()->avatar_path;
        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.jpg$#', $path);
        $this->assertStringNotContainsString('Selfie', $path);
        Storage::disk('public')->assertExists($path);

        $url = Storage::disk('public')->url($path);
        $this->actingAs($this->customer->fresh())->get(route('customer.account.show'))->assertOk()->assertSee($url, false)->assertSee('Replace picture')->assertSee('Remove');
        $this->actingAs($this->customer->fresh())->get(route('customer.dashboard'))->assertOk()->assertSee($url, false);
        $this->actingAs($this->owner)->get(route('owner.customers.index'))->assertOk()->assertSee($url, false);
        $this->actingAs($this->owner)->get(route('owner.customers.show', $this->customer))->assertOk()->assertSee($url, false);
    }

    public function test_png_and_webp_are_accepted_and_the_owner_can_have_one_too(): void
    {
        $this->upload($this->customer, $this->image('a.png', 'png'))->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.png', $this->customer->fresh()->avatar_path);

        $this->upload($this->owner, $this->image('b.webp', 'webp'), 'owner')->assertRedirect(route('owner.account.show').'#profile')->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.webp', $this->owner->fresh()->avatar_path);
        $this->actingAs($this->owner->fresh())->get(route('owner.dashboard'))->assertSee(Storage::disk('public')->url($this->owner->fresh()->avatar_path), false);
    }

    public function test_replacing_deletes_the_old_file_and_remove_deletes_the_picture(): void
    {
        $this->upload($this->customer, $this->image());
        $first = $this->customer->fresh()->avatar_path;
        $this->upload($this->customer, $this->image('new.png', 'png'));
        $second = $this->customer->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->actingAs($this->customer)->delete(route('customer.account.avatar.remove'))->assertSessionHas('status', 'Profile picture removed.');
        $this->assertNull($this->customer->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($second);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->actingAs($this->customer)->delete(route('customer.account.avatar.remove'))->assertSessionHas('status', 'You have no profile picture.');
        $this->actingAs($this->customer->fresh())->get(route('customer.account.show'))->assertSee('Upload picture')->assertSeeInOrder(['data-user-avatar>', 'AR', '</span>'], false);
    }

    /** @return array<string, array{\Closure(self): UploadedFile}> */
    public static function badFiles(): array
    {
        return [
            'too large' => [fn (self $t) => $t->image('big.jpg', 'jpeg', 2 * 1024 * 1024 + 10)],
            'a gif' => [fn (self $t) => $t->raw('a.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;")],
            'a script named .jpg' => [fn (self $t) => $t->raw('shell.jpg', '<?php echo "hi"; ?>')],
            'text named .png' => [fn (self $t) => $t->raw('x.png', 'just some text')],
            'a png named .webp' => [fn (self $t) => $t->image('x.webp', 'png')],
            'a pdf' => [fn (self $t) => $t->raw('doc.pdf', "%PDF-1.4\n%%EOF")],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_wrong_types_and_large_files_are_refused_and_nothing_is_stored(\Closure $make): void
    {
        $this->from(route('customer.account.show'))->upload($this->customer, $make($this))
            ->assertRedirect(route('customer.account.show').'#profile')->assertSessionHasErrorsIn('avatar', 'avatar');

        $this->assertNull($this->customer->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_missing_file_is_refused_and_avatar_path_cannot_be_set_through_the_profile_form(): void
    {
        $this->actingAs($this->customer)->post(route('customer.account.avatar'))->assertSessionHasErrorsIn('avatar', ['avatar' => 'Choose a picture first.']);

        $this->actingAs($this->customer)->post(route('customer.account.profile'), ['name' => 'Ana Reyes', 'email' => $this->customer->email, 'avatar_path' => 'avatars/../../.env']);
        $this->assertNull($this->customer->fresh()->avatar_path);
    }

    public function test_initials_are_the_fallback_and_a_walk_in_never_has_a_picture(): void
    {
        $this->assertSame('AR', $this->customer->initials());
        $this->assertSame('M', User::factory()->make(['name' => 'Madonna'])->initials());
        $this->assertSame('JC', User::factory()->make(['name' => '  juan  dela  cruz '])->initials());
        $this->assertNull($this->customer->avatarUrl());

        $walkIn = User::factory()->walkIn()->create(['name' => 'Lito Ramos']);
        $walkIn->forceFill(['avatar_path' => 'avatars/x.jpg'])->save();
        $this->assertNull($walkIn->fresh()->avatarUrl());
        $this->expectException(ValidationException::class);
        app(AvatarService::class)->replace($walkIn, $this->image());
    }

    public function test_the_chat_carries_the_writers_picture_or_initials_never_for_lumi(): void
    {
        $this->upload($this->customer, $this->image());
        $customer = $this->customer->fresh();
        $conversation = app(ConversationService::class)->studioFor($customer);
        ChatMessage::factory()->from(MessageSender::Customer, $customer->id)->create(['conversation_id' => $conversation->id, 'body' => 'Hello']);
        ChatMessage::factory()->from(MessageSender::Owner, $this->owner->id)->create(['conversation_id' => $conversation->id, 'body' => 'Hi Ana']);
        ChatMessage::factory()->from(MessageSender::Assistant, null)->create(['conversation_id' => $conversation->id, 'body' => 'I am Lumi']);

        $rows = app(ChatPresenter::class)->messages($conversation, $this->owner, $conversation->messages()->orderBy('id')->get());

        $this->assertSame(Storage::disk('public')->url($customer->avatar_path), $rows[0]['avatar']['url']);
        $this->assertSame(['url' => null, 'initials' => 'JA'], $rows[1]['avatar']);
        $this->assertNull($rows[2]['avatar']);
    }
}
