import wordpress from '@wordpress/eslint-plugin';
import globals from 'globals';

export default [
	...wordpress.configs[ 'recommended-with-formatting' ].map( ( config ) => ( {
		...config,
		files: [ 'assets/**/*.js' ],
	} ) ),
	{
		files: [ 'assets/**/*.js' ],
		languageOptions: {
			globals: globals.browser,
			sourceType: 'script',
		},
	},
];
