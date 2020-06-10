<?php

namespace Anomaly\Streams\Platform\Ui\ControlPanel\Component\Navigation\Command;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Anomaly\Streams\Platform\Support\Breadcrumb;
use Anomaly\Streams\Platform\Ui\ControlPanel\ControlPanelBuilder;

/**
 * Class SetActiveNavigationLink
 *
 * @link   http://pyrocms.com/
 * @author PyroCMS, Inc. <support@pyrocms.com>
 * @author Ryan Thompson <ryan@pyrocms.com>
 */
class SetActiveNavigationLink
{

    /**
     * The control_panel builder.
     *
     * @var ControlPanelBuilder
     */
    protected $builder;

    /**
     * Create a new SetActiveNavigationLink instance.
     *
     * @param ControlPanelBuilder $builder
     */
    public function __construct(ControlPanelBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * Handle the command.
     *
     * @param Request              $request
     * @param Breadcrumb $breadcrumbs
     */
    public function handle(Request $request, Breadcrumb $breadcrumbs)
    {
        $links = $this->builder->getControlPanelNavigation();

        /*
         * If we already have an active link
         * then we don't need to do this.
         */
        if ($active = $links->active()) {
            return;
        }

        /* @var NavigationLink $link */
        foreach ($links as $link) {

            /*
             * Get the HREF for both the active
             * and loop iteration link.
             */
            $href       = Arr::get($link->getAttributes(), 'href');
            $activeHref = '';

            if ($active && $active instanceof NavigationLinkInterface) {
                $activeHref = Arr::get($active->getAttributes(), 'href');
            }

            /*
             * If the request URL does not even
             * contain the HREF then skip it.
             */
            if (!Str::contains($request->url(), $href)) {
                continue;
            }

            /*
             * Compare the length of the active HREF
             * and loop iteration HREF. The longer the
             * HREF the more detailed and exact it is and
             * the more likely it is the active HREF and
             * therefore the active link.
             */
            $hrefLength       = strlen($href);
            $activeHrefLength = strlen($activeHref);

            if ($hrefLength > $activeHrefLength) {
                $active = $link;
            }
        }

        // No active link!
        if (!$active) {
            return;
        }

        // Active navigation link!
        $active->setActive(true);

        // Authorize the active link.
        if ($active->policy && !Gate::any((array) $active->policy)) {
            abort(403);
        }

        // Add the bread crumb.
        if (($breadcrumb = $active->getBreadcrumb()) !== false) {
            $breadcrumbs->put($breadcrumb ?: $active->getTitle(), $active->getHref());
        }
    }
}
