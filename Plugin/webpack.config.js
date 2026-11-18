/**
 * Custom Webpack configuration extending @wordpress/scripts defaults.
 *
 * @package AIChatbot
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: './admin/src/index.js',
	},
	output: {
		...defaultConfig.output,
		path: require( 'path' ).resolve( __dirname, 'admin/build' ),
		filename: '[name].js',
	},
};
