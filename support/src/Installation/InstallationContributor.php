<?php

declare(strict_types=1);

namespace Nvl\Support\Installation;

/** Supplies immutable installation metadata from a loaded package provider. @api */
interface InstallationContributor
{
    /** Return package-owned configuration publication metadata without enabling capabilities. */
    public function installation(): PackageInstallation;
}
