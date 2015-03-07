<?php
    namespace Lasso\Petitions;

    use Backend\Facades\Backend;
    use System\Classes\PluginBase;

    class Plugin extends PluginBase
    {
        public function pluginDetails()
        {
            return [
                'name' => 'Petitions',
                'description' => 'Provides the interface for managing of petitions on EasternAdvocates.org.',
                'author' => 'Daniel Schultz',
                'icon' => 'icon-envelope'
            ];
        }

        public function registerPermissions()
        {
            return [
                'lasso.petitions.access_petitions' => ['label' => 'Manage petitions'],
                'lasso.petitions.access_signatures' => ['label' => 'Manage signatures'],
            ];
        }

        public function registerComponents()
        {
            return [
                'Lasso\Petitions\Components\Post' => 'petitionPost',
            ];
        }

        public function registerNavigation()
        {
            return [
                'petitions' => [
                    'label' => 'Petitions',
                    'url' => Backend::url('lasso/petitions/petitions'),
                    'icon' => 'icon-check-square',
                    'permissions' => ['lasso.petitions.*'],
                    'order' => 500,

                    'sideMenu' => [
                        'petitions' => [
                            'label' => 'Petitions',
                            'url' => Backend::url('lasso/petitions/petitions'),
                            'icon' => 'icon-check-square',
                            'permissions' => ['lasso.petitions.access_petitions'],
                        ],
                        'signatures' => [
                            'label' => 'Signatures',
                            'url' => Backend::url('lasso/petitions/signatures'),
                            'icon' => 'icon-check-square',
                            'permissions' => ['lasso.petitions.access_signatures'],
                        ],
                    ]
                ]
            ];
        }
    }