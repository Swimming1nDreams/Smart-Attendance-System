# Departments Module Testing Checklist

## Prerequisites
- Database migrations applied
- Admin user created and logged in
- Access to database for verification queries

---

## Test 1: View Department List

### Steps
1. Login as admin
2. Navigate to `/departments`
3. Observe the page

### Expected Result
- Page loads without errors
- Displays table with columns: Name, Description, Actions
- If departments exist, they are listed
- If no departments, shows "No departments found" message
- Search input field visible
- "Add Department" button visible
- Styling may be unstyled (Tailwind CSS not configured)

### Database Verification Query
```sql
SELECT id, name, description, created_at, updated_at 
FROM departments 
ORDER BY name ASC;
```

### Possible Errors to Watch For
- **404 error**: Route not configured (should not happen)
- **500 error**: Database connection failure
- **PHP warning**: Missing view file
- **Empty page**: View rendering issue
- **No data displayed**: Database query failure

---

## Test 2: Search Department

### Steps
1. Navigate to `/departments`
2. Enter search term in search box
3. Click "Search" button
4. Observe filtered results

### Expected Result
- Results filtered by search term
- Search matches both `name` and `description` fields
- Case-insensitive search
- Partial matches work (LIKE %term%)
- Empty search shows all departments
- Search term persists in input field

### Database Verification Query
```sql
-- Replace 'search_term' with actual search term used
SELECT id, name, description 
FROM departments 
WHERE name LIKE '%search_term%' 
   OR description LIKE '%search_term%'
ORDER BY name ASC;
```

### Possible Errors to Watch For
- **No results when expected**: SQL query issue
- **SQL error**: Special characters in search term
- **Search term lost**: Form submission issue
- **All results shown**: Search parameter not passed

---

## Test 3: Create Department

### Steps
1. Navigate to `/departments`
2. Click "Add Department" button
3. Fill in form:
   - Department Name: "Engineering"
   - Description: "Software development team"
4. Click "Save" button
5. Observe result

### Expected Result
- Redirects to `/departments`
- Flash message: "Department added successfully."
- New department appears in list
- Department data saved correctly

### Database Verification Query
```sql
-- Verify department was created
SELECT id, name, description, created_at, updated_at 
FROM departments 
WHERE name = 'Engineering';

-- Verify uniqueness constraint still works
SELECT COUNT(*) FROM departments WHERE name = 'Engineering';
```

### Possible Errors to Watch For
- **Validation error**: Name exceeds 255 characters
- **Validation error**: Description exceeds 1000 characters
- **Duplicate error**: Department name already exists
- **Redirect failure**: Header not sent
- **Flash message not shown**: Session issue
- **Data not saved**: Database write failure

---

## Test 4: Edit Department

### Steps
1. Navigate to `/departments`
2. Click "Edit" link on a department
3. Modify form:
   - Department Name: "Engineering Team"
   - Description: "Software and hardware development"
4. Click "Save" button
5. Observe result

### Expected Result
- Redirects to `/departments`
- Flash message: "Department updated successfully."
- Department details updated in list
- `updated_at` timestamp changed

### Database Verification Query
```sql
-- Verify department was updated
SELECT id, name, description, created_at, updated_at 
FROM departments 
WHERE name = 'Engineering Team';

-- Check updated_at is recent
SELECT updated_at > created_at as was_updated 
FROM departments 
WHERE name = 'Engineering Team';
```

### Possible Errors to Watch For
- **404 error**: Department not found
- **Validation error**: Same as create
- **Duplicate error**: Name conflicts with another department
- **Data not updated**: Database write failure
- **Old data shown**: Cache issue

---

## Test 5: Delete Department (No Assigned Employees)

### Steps
1. Create a test department with no employees
2. Navigate to `/departments`
3. Click "Delete" button on test department
4. Confirm deletion dialog
5. Observe result

### Expected Result
- Redirects to `/departments`
- Flash message: "Department deleted successfully."
- Department removed from list
- Department removed from database

### Database Verification Query
```sql
-- Verify department was deleted
SELECT COUNT(*) FROM departments WHERE name = 'Test Department';

-- Should return 0
```

### Possible Errors to Watch For
- **Delete blocked**: Foreign key constraint violation
- **Flash message error**: "Department not found."
- **Still in list**: Delete query failed
- **Cascade delete**: Employees deleted (should not happen with SET NULL)

---

## Test 6: Delete Protection (With Assigned Employee)

### Steps
1. Create a test department
2. Create an employee assigned to this department
3. Navigate to `/departments`
4. Click "Delete" button on test department
5. Confirm deletion dialog
6. Observe result

### Expected Result
- Redirects to `/departments`
- Flash message: "Cannot delete this department because employees are assigned to it."
- Department still exists in list
- Department NOT deleted from database
- Employee still has department_id assigned

### Database Verification Query
```sql
-- Verify department still exists
SELECT id, name FROM departments WHERE name = 'Test Department';

-- Verify employee still assigned
SELECT id, name, department_id FROM users WHERE department_id = (
    SELECT id FROM departments WHERE name = 'Test Department'
);

-- Count assigned users
SELECT COUNT(*) FROM users WHERE department_id = (
    SELECT id FROM departments WHERE name = 'Test Department'
);
```

### Possible Errors to Watch For
- **Department deleted**: Protection logic failed
- **Employee department_id NULL**: Cascade delete triggered
- **Different error message**: Exception not caught
- **500 error**: Database query failure

---

## Edge Cases to Test

### Empty Description
- Create department with empty description
- Should work (description is nullable)

### Special Characters in Name
- Create department with: "R&D" or "C&A"
- Should work (VARCHAR supports special chars)

### Very Long Description
- Create department with 1000+ character description
- Should fail validation

### Duplicate Name
- Try creating department with existing name
- Should fail with "A department with this name already exists."

### SQL Injection Attempt
- Search with: `' OR 1=1 --`
- Should be safe (PDO prepared statements)

### XSS Attempt
- Create department with: `<script>alert('xss')</script>`
- Should be escaped in view (htmlspecialchars used)

---

## Session/Authentication Tests

### Access Without Login
- Try accessing `/departments` while logged out
- Should redirect to `/login`

### Access as Employee
- Login as employee (not admin)
- Try accessing `/departments`
- Should redirect to `/access-denied`

---

## Performance Tests

### Large Dataset
- Create 100+ departments
- Test page load time
- Test search performance

---

## Browser Compatibility

- Test in Chrome
- Test in Firefox
- Test in Edge
- Test in Safari (if available)

---

## Post-Test Cleanup

```sql
-- Remove test data
DELETE FROM departments WHERE name LIKE 'Test%';
DELETE FROM departments WHERE name = 'Engineering Team';
DELETE FROM departments WHERE name = 'Engineering';

-- Reset employee department_id if needed
UPDATE users SET department_id = NULL WHERE department_id NOT IN (
    SELECT id FROM departments
);
```

---

## Test Results Log

| Test | Status | Notes | Date |
|------|--------|-------|------|
| View Department List | ⬜ | | |
| Search Department | ⬜ | | |
| Create Department | ⬜ | | |
| Edit Department | ⬜ | | |
| Delete Department (no employees) | ⬜ | | |
| Delete Protection (with employees) | ⬜ | | |
| Empty Description | ⬜ | | |
| Special Characters | ⬜ | | |
| Duplicate Name | ⬜ | | |
| SQL Injection | ⬜ | | |
| XSS Attempt | ⬜ | | |
| Access Without Login | ⬜ | | |
| Access as Employee | ⬜ | | |
