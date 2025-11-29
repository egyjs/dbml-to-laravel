# **🤝 Contributing to DBML to Laravel Eloquent Generator**

We warmly welcome contributions to the `egyjs/dbml-to-laravel` project! Your help is invaluable in making this tool even better for Laravel developers. By contributing, you help streamline database schema to code generation for the entire community.

Please take a moment to review this document to understand how to contribute effectively and ensure a smooth collaboration process.

## **🗣️ Initial Discussion**

Before you start working on a significant change (e.g., a new feature, a major refactor), we highly recommend discussing your proposed changes via a [GitHub Issue](https://github.com/egyjs/dbml-to-laravel/issues) first. This helps manage expectations, prevents wasted effort, and ensures your contribution aligns with the project's goals and roadmap.

## **📜 Code of Conduct**

To ensure a welcoming and inclusive environment for everyone, all contributors are expected to adhere to our [Code of Conduct](.github/CODE_OF_CONDUCT.md). Please read it carefully. By participating in this project, you agree to abide by its terms.

## **💡 Types of Contributions We Welcome**

We appreciate all forms of contributions, not just code! Here are some ways you can help:

* **Bug Reports:** Identify and report issues you encounter. Provide clear steps to reproduce the bug.
* **Bug Fixes:** Submit pull requests to fix existing bugs.
* **New Features:** Propose and implement new functionalities that enhance the package's capabilities (e.g., support for more DBML features, additional Laravel relationship types, custom casts).
* **Documentation Improvements:** Enhance our `README.md`, add more examples, or create tutorials. Clear documentation is crucial for user adoption.
* **Refactoring & Code Quality:** Help improve the codebase's readability, maintainability, and performance.
* **Testing:** Write new tests or improve existing ones to ensure the reliability of the package.

## **🛠️ Pull Request Process**

Follow these steps to contribute code via a Pull Request (PR):

1. **Fork the repository:** Click the "Fork" button on the top right of the [repository page](https://github.com/egyjs/dbml-to-laravel).
2. **Clone your forked repository:**
```bash
git clone https://github.com/egyjs/dbml-to-laravel.git
cd dbml-to-laravel
```
3. **Create a new branch:** Always create a new branch for your changes, ideally from the `main` branch. Use a descriptive name (e.g., `feature/add-soft-deletes`, `fix/parsing-issue-123`).
```bash
git checkout main
git pull origin main
git checkout -b feature/your-awesome-feature
```
4. **Install dependencies:**
```bash
composer install
```
   The package ships with a pre-bundled DBML parser, so running `npm install` is only required when you modify `bin/parse-dbml.js`. After making parser changes execute `npm run build-parser` and commit the regenerated `bin/parse-dbml.runtime.cjs` alongside your updates.
5. **Write tests (if applicable):** If you're fixing a bug, write a test that reproduces the bug. If you're adding a new feature, write tests to cover its functionality.
```bash
# Run all tests
./vendor/bin/pest --ci
```
6. **Make your code changes.**
7. **Ensure code style and formatting:** We follow PSR-12 coding standards. You can use PHP-CS-Fixer to automatically format your code:
   ./vendor/bin/php-cs-fixer fix

8. **Update `README.md` (if necessary):** If your changes affect the package's interface, installation, or usage, please update the `README.md` accordingly.
9. **Commit your changes:** Write clear, concise commit messages.
```bash
git add .
git commit -m "feat: Add support for soft deletes"
```
   * **Sign your commits:** We may require signed commits for security and traceability.
10. **Push your branch to your forked repository:**
```
git push origin feature/your-awesome-feature
```

11. **Create a Pull Request:** Go to your forked repository on GitHub and click the "Compare & pull request" button.
    * Provide a clear title and detailed description of your changes.
    * Reference any related issues (e.g., `Closes #123`).

### **Versioning**

For new features or breaking changes, contributors should be aware that version numbers (following [Semantic Versioning \- SemVer](https://semver.org/)) will be increased by maintainers. You don't need to update version numbers in any files within your PR, but be mindful of the impact of your changes.

### **Merge Approval**

Pull requests will be reviewed by project maintainers. We may request changes or further discussion before merging. We aim to review PRs promptly.

## **📏 Coding Guidelines**

* **PHP Standards:** Adhere to [PSR-12 Extended Coding Style](https://www.php-fig.org/psr/psr-12/).
* **Laravel Conventions:** Follow standard Laravel conventions for models, migrations, and other components.
* **Readability:** Write clean, well-commented, and easily understandable code.

Thank you for considering contributing to `egyjs/dbml-to-laravel`\! We appreciate your efforts.
