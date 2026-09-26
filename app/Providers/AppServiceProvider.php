<?php

namespace App\Providers;

use App\Models\Deal;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RawMaterial;
use App\Models\ReadyItem;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentBranch::class);
    }

    public function boot(): void
    {
        $this->registerBlueprintMacros();

        JsonResource::withoutWrapping();
        Model::preventLazyLoading(! $this->app->isProduction());

        // Short, stable names in *_type columns (stock ledger, recipes). Not enforced: the
        // activity log stores class names.
        Relation::morphMap([
            'raw_material' => RawMaterial::class,
            'ready_item' => ReadyItem::class,
            'menu_item' => MenuItem::class,
            'menu_item_variant' => MenuItemVariant::class,
            'modifier' => Modifier::class,
            'deal' => Deal::class,
            'order' => Order::class,
            'order_item' => OrderItem::class,
        ]);

        if ($this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(base_path('tests/Fixtures/migrations'));
        }
    }

    /**
     * $table->publicUuid()  — public key (CLAUDE.md §2)
     * $table->trashable()   — our own soft delete columns (CLAUDE.md §1)
     */
    private function registerBlueprintMacros(): void
    {
        Blueprint::macro('publicUuid', function () {
            /** @var Blueprint $this */
            return $this->uuid('uuid')->unique();
        });

        Blueprint::macro('trashable', function () {
            /** @var Blueprint $this */
            $this->timestamp('deleted_at')->nullable()->index();
            $this->unsignedBigInteger('deleted_by')->nullable();
            $this->string('delete_reason', 500)->nullable();
            $this->uuid('trash_batch')->nullable()->index();
        });
    }
}
