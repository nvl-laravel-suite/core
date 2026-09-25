/**
 * Import and spread this array into the consumer's ESLint flat configuration.
 * NVL Data verifies these generator-owned artifacts with types:check.
 */
export default [
    {
        name: 'nvl/generated-types',
        ignores: [
            'resources/js/types/generated.d.ts',
            'resources/js/types/generated/**',
            'resources/js/types/generated.manifest.json',
        ],
    },
];
