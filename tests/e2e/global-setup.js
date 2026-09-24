const { seed } = require( './seed' );

module.exports = async function globalSetup() {
	seed();
};
