# UI Design Preview

When changing any user-facing UI or visual design, keep the relevant preview surface in sync during the same task.

The preview must account for every affected design area in the project, including pages, layouts, navigation, forms, tables, cards, modals, filters, empty states, loading states, error states, responsive states, and dark mode when the project supports it.

Use the preview surface that exists for the current project: a Blade preview page, Storybook story, component playground, screenshot fixture, or the affected view itself. Do not introduce domain-specific examples unless the project actually has that domain.

Every project should expose the design preview through a stable route or page, such as `/design-preview`, in local/development or behind appropriate access control. If no dedicated preview exists yet, create one or update the affected route directly before treating the UI change as complete.
