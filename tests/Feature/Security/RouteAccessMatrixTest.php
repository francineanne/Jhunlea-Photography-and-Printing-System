<?php

namespace Tests\Feature\Security;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\BookingReferenceImage;
use App\Models\OrderFile;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9A: who may call every route. MATRIX lists EVERY route (method and address) with its access class; a route
 * that is added without a line here, or a line whose route is gone, fails the first test, so a new route can never
 * arrive unprotected by accident. Public routes are listed as public on purpose.
 *
 * The rule for another customer's record (docs/SECURITY_AUDIT.md, finding F-03): 403 Forbidden, from the record's
 * policy. Two kinds answer 404 instead, on purpose and unchanged: a conversation (ConversationPolicy denies as not
 * found, and the chat's JSON codes depend on it) and a notification (looked up only among the user's own rows). Both
 * are asserted in CROSS_USER.
 */
class RouteAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Anyone, signed in or not; reads published data only. */
    private const PUBLIC = 'public';

    /** Only visitors who are not signed in; a signed-in person is sent to their own home. */
    private const GUEST = 'guest';

    /** Any signed-in person; the record's policy decides (owner, or the customer whose record it is). */
    private const SIGNED_IN = 'signed_in';

    /** auth + role:customer: a guest goes to login, the owner gets 403. */
    private const CUSTOMER = 'customer';

    /** auth + role:owner: a guest goes to login, a customer gets 403. */
    private const OWNER = 'owner';

    /** Laravel's own: the health check, the local-only component gallery and the 404 fallback. */
    private const FRAMEWORK = 'framework';

    private const MATRIX = [
        // PUBLIC
        'GET /' => self::PUBLIC,
        'GET faq' => self::PUBLIC,
        'GET packages' => self::PUBLIC,
        'GET packages/compare' => self::PUBLIC,
        'GET packages/finder' => self::PUBLIC,
        'GET packages/{slug}' => self::PUBLIC,
        'GET services' => self::PUBLIC,
        // GUEST
        'POST assistant/messages' => self::GUEST,
        'GET forgot-password' => self::GUEST,
        'POST forgot-password' => self::GUEST,
        'GET login' => self::GUEST,
        'POST login' => self::GUEST,
        'GET register' => self::GUEST,
        'POST register' => self::GUEST,
        'POST reset-password' => self::GUEST,
        'GET reset-password/{token}' => self::GUEST,
        // SIGNED_IN
        'GET booking-images/{image}' => self::SIGNED_IN,
        'GET booking-images/{image}/download' => self::SIGNED_IN,
        'GET files/{file}' => self::SIGNED_IN,
        'POST logout' => self::SIGNED_IN,
        // CUSTOMER
        'GET my/account' => self::CUSTOMER,
        'POST my/account/password' => self::CUSTOMER,
        'POST my/account/profile' => self::CUSTOMER,
        'POST my/account/theme' => self::CUSTOMER,
        'GET my/assistant' => self::CUSTOMER,
        'GET my/assistant/conversations' => self::CUSTOMER,
        'POST my/assistant/conversations' => self::CUSTOMER,
        'GET my/assistant/conversations/{conversation}/handoff-draft' => self::CUSTOMER,
        'POST my/bookings' => self::CUSTOMER,
        'GET my/bookings' => self::CUSTOMER,
        'GET my/bookings/new' => self::CUSTOMER,
        'GET my/bookings/slots' => self::CUSTOMER,
        'GET my/bookings/{booking}' => self::CUSTOMER,
        'POST my/bookings/{booking}/accept' => self::CUSTOMER,
        'POST my/bookings/{booking}/another-time' => self::CUSTOMER,
        'POST my/bookings/{booking}/cancel' => self::CUSTOMER,
        'GET my/bookings/{booking}/sent' => self::CUSTOMER,
        'GET my/dashboard' => self::CUSTOMER,
        'GET my/messages/studio' => self::CUSTOMER,
        'GET my/messages/unread' => self::CUSTOMER,
        'GET my/messages/{conversation}' => self::CUSTOMER,
        'POST my/messages/{conversation}' => self::CUSTOMER,
        'POST my/messages/{conversation}/lumi' => self::CUSTOMER,
        'POST my/messages/{conversation}/read' => self::CUSTOMER,
        'GET my/notifications' => self::CUSTOMER,
        'POST my/notifications/read-all' => self::CUSTOMER,
        'GET my/notifications/{notification}' => self::CUSTOMER,
        'POST my/notifications/{notification}/open' => self::CUSTOMER,
        'POST my/notifications/{notification}/read' => self::CUSTOMER,
        'POST my/printing' => self::CUSTOMER,
        'GET my/printing' => self::CUSTOMER,
        'GET my/printing/estimate' => self::CUSTOMER,
        'GET my/printing/new' => self::CUSTOMER,
        'GET my/printing/{order}' => self::CUSTOMER,
        'POST my/printing/{order}/cancel' => self::CUSTOMER,
        'POST my/printing/{order}/files' => self::CUSTOMER,
        'DELETE my/printing/{order}/files/{file}' => self::CUSTOMER,
        'GET my/printing/{order}/placed' => self::CUSTOMER,
        'GET my/requests' => self::CUSTOMER,
        // OWNER
        'GET owner' => self::OWNER,
        'GET owner/account' => self::OWNER,
        'POST owner/account/password' => self::OWNER,
        'POST owner/account/profile' => self::OWNER,
        'POST owner/account/theme' => self::OWNER,
        'GET owner/activity' => self::OWNER,
        'GET owner/activity/csv' => self::OWNER,
        'GET owner/assistant' => self::OWNER,
        'POST owner/assistant/ask' => self::OWNER,
        'GET owner/assistant/briefing' => self::OWNER,
        'GET owner/assistant/history' => self::OWNER,
        'GET owner/bookings' => self::OWNER,
        'POST owner/bookings/walk-in' => self::OWNER,
        'GET owner/bookings/walk-in/new' => self::OWNER,
        'GET owner/bookings/walk-in/slots' => self::OWNER,
        'GET owner/bookings/{booking}' => self::OWNER,
        'POST owner/bookings/{booking}/cancel' => self::OWNER,
        'POST owner/bookings/{booking}/complete' => self::OWNER,
        'POST owner/bookings/{booking}/confirm' => self::OWNER,
        'POST owner/bookings/{booking}/notes' => self::OWNER,
        'POST owner/bookings/{booking}/payments' => self::OWNER,
        'POST owner/bookings/{booking}/payments/{payment}/void' => self::OWNER,
        'POST owner/bookings/{booking}/price' => self::OWNER,
        'POST owner/bookings/{booking}/propose' => self::OWNER,
        'POST owner/bookings/{booking}/reject' => self::OWNER,
        'GET owner/customers' => self::OWNER,
        'GET owner/customers/{customer}' => self::OWNER,
        'GET owner/faqs' => self::OWNER,
        'POST owner/faqs' => self::OWNER,
        'GET owner/faqs/create' => self::OWNER,
        'PUT owner/faqs/{faq}' => self::OWNER,
        'DELETE owner/faqs/{faq}' => self::OWNER,
        'GET owner/faqs/{faq}/delete' => self::OWNER,
        'GET owner/faqs/{faq}/edit' => self::OWNER,
        'POST owner/faqs/{faq}/move' => self::OWNER,
        'POST owner/faqs/{faq}/publish' => self::OWNER,
        'GET owner/messages' => self::OWNER,
        'GET owner/messages/presence' => self::OWNER,
        'POST owner/messages/presence' => self::OWNER,
        'POST owner/messages/presence/heartbeat' => self::OWNER,
        'GET owner/messages/{conversation}' => self::OWNER,
        'POST owner/messages/{conversation}' => self::OWNER,
        'POST owner/messages/{conversation}/assist/suggest' => self::OWNER,
        'POST owner/messages/{conversation}/assist/summarize' => self::OWNER,
        'POST owner/messages/{conversation}/read' => self::OWNER,
        'POST owner/messages/{conversation}/reopen' => self::OWNER,
        'POST owner/messages/{conversation}/resolve' => self::OWNER,
        'GET owner/notifications' => self::OWNER,
        'POST owner/notifications/read-all' => self::OWNER,
        'GET owner/notifications/{notification}' => self::OWNER,
        'POST owner/notifications/{notification}/open' => self::OWNER,
        'POST owner/notifications/{notification}/read' => self::OWNER,
        'GET owner/packages' => self::OWNER,
        'POST owner/packages' => self::OWNER,
        'GET owner/packages/create' => self::OWNER,
        'PUT owner/packages/{package}' => self::OWNER,
        'DELETE owner/packages/{package}' => self::OWNER,
        'GET owner/packages/{package}/edit' => self::OWNER,
        'POST owner/packages/{package}/feature' => self::OWNER,
        'POST owner/packages/{package}/images' => self::OWNER,
        'PUT owner/packages/{package}/images/{image}' => self::OWNER,
        'DELETE owner/packages/{package}/images/{image}' => self::OWNER,
        'POST owner/packages/{package}/images/{image}/cover' => self::OWNER,
        'POST owner/packages/{package}/publish' => self::OWNER,
        'GET owner/payments' => self::OWNER,
        'POST owner/payments/counter' => self::OWNER,
        'GET owner/payments/counter/search' => self::OWNER,
        'GET owner/payments/counter/target' => self::OWNER,
        'GET owner/payments/{payment}/receipt' => self::OWNER,
        'GET owner/printing-orders' => self::OWNER,
        'POST owner/printing-orders/walk-in' => self::OWNER,
        'GET owner/printing-orders/walk-in/new' => self::OWNER,
        'GET owner/printing-orders/walk-in/price' => self::OWNER,
        'GET owner/printing-orders/{order}' => self::OWNER,
        'POST owner/printing-orders/{order}/accept' => self::OWNER,
        'POST owner/printing-orders/{order}/cancel' => self::OWNER,
        'POST owner/printing-orders/{order}/complete' => self::OWNER,
        'POST owner/printing-orders/{order}/info' => self::OWNER,
        'POST owner/printing-orders/{order}/payments' => self::OWNER,
        'POST owner/printing-orders/{order}/payments/{payment}/void' => self::OWNER,
        'POST owner/printing-orders/{order}/price' => self::OWNER,
        'POST owner/printing-orders/{order}/processing' => self::OWNER,
        'POST owner/printing-orders/{order}/ready' => self::OWNER,
        'POST owner/printing-orders/{order}/reject' => self::OWNER,
        'POST owner/printing-orders/{order}/review' => self::OWNER,
        'GET owner/printing-orders/{order}/walk-in-saved' => self::OWNER,
        'GET owner/reports' => self::OWNER,
        'GET owner/reports/income' => self::OWNER,
        'GET owner/reports/income/csv' => self::OWNER,
        'GET owner/reports/income/end-of-day' => self::OWNER,
        'GET owner/schedule' => self::OWNER,
        'POST owner/schedule/blocked' => self::OWNER,
        'DELETE owner/schedule/blocked/{blockedDate}' => self::OWNER,
        'GET owner/schedule/edit' => self::OWNER,
        'POST owner/schedule/hours' => self::OWNER,
        'POST owner/schedule/settings' => self::OWNER,
        'GET owner/services' => self::OWNER,
        'POST owner/services' => self::OWNER,
        'GET owner/services/create' => self::OWNER,
        'PUT owner/services/{service}' => self::OWNER,
        'DELETE owner/services/{service}' => self::OWNER,
        'GET owner/services/{service}/edit' => self::OWNER,
        'POST owner/services/{service}/options' => self::OWNER,
        'PUT owner/services/{service}/options/{option}' => self::OWNER,
        'DELETE owner/services/{service}/options/{option}' => self::OWNER,
        'POST owner/services/{service}/publish' => self::OWNER,
        'GET owner/settings' => self::OWNER,
        'POST owner/settings/business' => self::OWNER,
        'POST owner/settings/uploads' => self::OWNER,
        'POST owner/settings/uploads/reset' => self::OWNER,
        'GET owner/themes' => self::OWNER,
        'POST owner/themes' => self::OWNER,
        'GET owner/themes/create' => self::OWNER,
        'PUT owner/themes/{theme}' => self::OWNER,
        'DELETE owner/themes/{theme}' => self::OWNER,
        'GET owner/themes/{theme}/edit' => self::OWNER,
        'POST owner/themes/{theme}/publish' => self::OWNER,
        'GET owner/walk-in/customers' => self::OWNER,
        // FRAMEWORK
        'GET ui-kit' => self::FRAMEWORK,
        'GET up' => self::FRAMEWORK,
        'GET {fallbackPlaceholder}' => self::FRAMEWORK,
    ];

    /**
     * Every customer or signed-in route that takes a record of a customer: route name, the parameters (built from Ana's
     * records), the method, and what Ben (another customer) gets. Ana herself is checked on the read routes.
     */
    private const CROSS_USER = [
        ['customer.printing.show', ['order'], 'GET', 403],
        ['customer.printing.placed', ['order'], 'GET', 403],
        ['customer.printing.cancel', ['order'], 'POST', 403],
        ['customer.printing.files.store', ['order'], 'POST', 403],
        ['customer.printing.files.destroy', ['order', 'file'], 'DELETE', 403],
        ['files.download', ['file'], 'GET', 403],
        ['customer.bookings.show', ['booking'], 'GET', 403],
        ['customer.bookings.sent', ['booking'], 'GET', 403],
        ['customer.bookings.cancel', ['booking'], 'POST', 403],
        ['customer.bookings.accept', ['booking'], 'POST', 403],
        ['customer.bookings.another-time', ['booking'], 'POST', 403],
        ['booking-images.show', ['image'], 'GET', 403],
        ['booking-images.download', ['image'], 'GET', 403],
        ['customer.messages.show', ['studio'], 'GET', 404],
        ['customer.messages.store', ['studio'], 'POST', 404],
        ['customer.messages.lumi', ['studio'], 'POST', 404],
        ['customer.messages.read', ['studio'], 'POST', 404],
        ['customer.assistant.handoff-draft', ['lumi'], 'GET', 404],
        ['customer.notifications.go', ['notification'], 'GET', 404],
        ['customer.notifications.open', ['notification'], 'POST', 404],
        ['customer.notifications.read', ['notification'], 'POST', 404],
    ];

    /** @return array<string, RouteDefinition> keyed like MATRIX */
    private function routes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routes[$route->methods()[0].' '.$route->uri()] = $route;
        }

        return $routes;
    }

    public function test_every_route_is_in_the_matrix_and_every_line_is_a_route(): void
    {
        $routes = array_keys($this->routes());

        $unlisted = array_diff($routes, array_keys(self::MATRIX));
        $this->assertSame([], array_values($unlisted), 'New routes without an access rule: add them to RouteAccessMatrixTest::MATRIX (and CROSS_USER when they take a customer\'s record)');

        $gone = array_diff(array_keys(self::MATRIX), $routes);
        $this->assertSame([], array_values($gone), 'MATRIX lists routes that no longer exist');
    }

    public function test_the_middleware_of_every_route_matches_its_class(): void
    {
        foreach ($this->routes() as $key => $route) {
            $middleware = $route->gatherMiddleware();
            $class = self::MATRIX[$key];

            $expected = match ($class) {
                self::OWNER => ['auth', 'role:owner'],
                self::CUSTOMER => ['auth', 'role:customer'],
                self::SIGNED_IN => ['auth'],
                self::GUEST => ['guest'],
                default => [],
            };
            foreach ($expected as $name) {
                $this->assertContains($name, $middleware, "{$key} ({$class}) lacks {$name}");
            }

            if (in_array($class, [self::PUBLIC, self::GUEST], true)) {
                $this->assertNotContains('auth', $middleware, "{$key} is {$class} but asks for a login");
            }
            if ($class !== self::OWNER) {
                $this->assertNotContains('role:owner', $middleware, "{$key} is {$class} but owner only");
            }

            // Every route but Laravel's own has its own named counter (ThrottleCountersTest checks the names are unique)
            if ($class !== self::FRAMEWORK) {
                $throttle = collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
                $this->assertNotNull($throttle, "{$key} has no throttle");
                $this->assertNotNull(explode(',', $throttle)[2] ?? null, "{$key} has an unnamed throttle");
            }
        }
    }

    /** A value for each parameter that passes the route's own pattern; the record need not exist (see below). */
    private function url(RouteDefinition $route): string
    {
        $uri = '/'.ltrim($route->uri(), '/');

        foreach ($route->parameterNames() as $name) {
            $pattern = $route->wheres[$name] ?? null;
            $value = match (true) {
                $name === 'fallbackPlaceholder' => 'no-such-page-'.Str::random(6),
                $pattern !== null && str_contains($pattern, 'a-fA-F') => (string) Str::uuid(),
                $name === 'slug' => 'no-such-package',
                $name === 'token' => 'some-token',
                default => '1',
            };
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $value, $uri);
        }

        return $uri;
    }

    /**
     * A guest is sent to login by `auth`; a person with the wrong role gets 403 from EnsureRole, which runs BEFORE route
     * model binding (bootstrap/app.php), so the record in the address does not even have to exist.
     */
    public function test_guests_and_the_wrong_role_are_turned_away_from_every_route(): void
    {
        $customer = User::factory()->create();
        $owner = User::factory()->owner()->create();

        foreach ($this->routes() as $key => $route) {
            $method = $route->methods()[0];
            $url = $this->url($route);

            switch (self::MATRIX[$key]) {
                case self::OWNER:
                    $this->flushGuest();
                    $this->call($method, $url)->assertRedirect(route('login'));
                    $this->assertSame(403, $this->actingAs($customer)->call($method, $url)->status(), "{$key}: a customer must get 403");
                    break;

                case self::CUSTOMER:
                    $this->flushGuest();
                    $this->call($method, $url)->assertRedirect(route('login'));
                    $this->assertSame(403, $this->actingAs($owner)->call($method, $url)->status(), "{$key}: the owner must get 403");
                    break;

                case self::SIGNED_IN:
                    $this->flushGuest();
                    $this->call($method, $url)->assertRedirect(route('login'));
                    break;

                case self::GUEST:
                    $this->actingAs($customer)->call($method, $url)->assertRedirect($customer->role->home());
                    $this->actingAs($owner)->call($method, $url)->assertRedirect($owner->role->home());
                    break;

                case self::PUBLIC:
                    if ($route->parameterNames() === []) {
                        $this->flushGuest();
                        $this->assertSame(200, $this->call($method, $url)->status(), "{$key}: public page must open for a guest");
                    }
                    break;
            }
        }

        // Laravel's own: the health check works, the gallery is hidden outside local, anything unknown is 404
        $this->flushGuest();
        $this->get('/up')->assertOk();
        $this->get('/ui-kit')->assertNotFound();
        $this->get('/no-such-page')->assertNotFound();
    }

    private function flushGuest(): void
    {
        auth()->logout();
        $this->flushSession();
    }

    public function test_one_customer_never_reaches_another_customers_records(): void
    {
        Storage::fake(config('printing.disk'));
        Storage::fake(config('booking.disk'));

        $ana = User::factory()->create(['name' => 'Ana']);
        $ben = User::factory()->create(['name' => 'Ben']);
        $records = $this->recordsOf($ana);

        foreach (self::CROSS_USER as [$name, $keys, $method, $expected]) {
            $url = route($name, array_map(fn (string $key) => $records[$key], $keys));

            $this->flushGuest();
            $this->call($method, $url)->assertRedirect(route('login'));

            $status = $this->actingAs($ben)->call($method, $url, ['note' => 'x', 'body' => 'x', 'reason' => 'x'])->status();
            $this->assertSame($expected, $status, "{$name}: another customer must get {$expected}");
        }

        // Nothing of Ana's changed, and Ana herself still reaches her own records
        $this->assertSame(PrintingOrderStatus::Submitted, $records['order']->fresh()->status);
        $this->assertSame(BookingStatus::Requested, $records['booking']->fresh()->status);
        $this->assertNotNull($records['file']->fresh());
        $this->assertNull($records['notification']->fresh()->read_at);

        $this->flushGuest();
        $this->actingAs($ana)->get(route('customer.printing.show', $records['order']))->assertOk();
        $this->actingAs($ana)->get(route('customer.bookings.show', $records['booking']))->assertOk();
        $this->actingAs($ana)->get(route('files.download', $records['file']))->assertOk();
        $this->actingAs($ana)->get(route('booking-images.download', $records['image']))->assertOk();
        $this->actingAs($ana)->getJson(route('customer.messages.show', $records['studio']))->assertOk();
        $this->actingAs($ana)->getJson(route('customer.assistant.handoff-draft', $records['lumi']))->assertOk();
    }

    public function test_every_route_that_takes_a_customers_record_is_in_the_cross_user_list(): void
    {
        $covered = array_column(self::CROSS_USER, 0);

        foreach ($this->routes() as $key => $route) {
            if (in_array(self::MATRIX[$key], [self::CUSTOMER, self::SIGNED_IN], true) && $route->parameterNames() !== []) {
                $this->assertContains($route->getName(), $covered, "{$key} takes a record: add it to CROSS_USER");
            }
        }
    }

    /** @return array<string, mixed> */
    private function recordsOf(User $customer): array
    {
        $order = PrintingOrder::factory()->status(PrintingOrderStatus::Submitted)->forCustomer($customer)->create();
        $file = OrderFile::factory()->create(['printing_order_id' => $order->id, 'uploaded_by' => $customer->id, 'path' => "printing-orders/{$order->id}/a.pdf"]);
        OrderFile::factory()->create(['printing_order_id' => $order->id, 'uploaded_by' => $customer->id, 'path' => "printing-orders/{$order->id}/b.pdf"]);
        Storage::disk(config('printing.disk'))->put($file->path, '%PDF-1.4 test');

        $booking = PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($customer)->create();
        $image = BookingReferenceImage::factory()->create(['photography_booking_id' => $booking->id, 'uploaded_by' => $customer->id, 'path' => "booking-images/{$booking->id}/a.jpg"]);
        Storage::disk(config('booking.disk'))->put($image->path, 'not really a picture');

        $conversations = app(ConversationService::class);

        $notification = new DatabaseNotification;
        $notification->forceFill([
            'id' => (string) Str::uuid(), 'type' => 'order_accepted', 'notifiable_type' => 'user', 'notifiable_id' => $customer->id,
            'data' => ['kind' => 'order_accepted', 'payable_type' => 'printing_order', 'payable_id' => $order->id, 'reference' => $order->reference_number],
        ])->save();

        return [
            'order' => $order, 'file' => $file, 'booking' => $booking, 'image' => $image, 'notification' => $notification,
            'studio' => $conversations->studioFor($customer), 'lumi' => $conversations->startAssistantConversation($customer),
        ];
    }
}
